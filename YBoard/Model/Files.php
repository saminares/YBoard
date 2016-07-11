<?php
namespace YBoard\Model;

use YBoard\Data\File;
use YBoard\Data\UploadedFile;
use YBoard\Exceptions\FileUploadException;
use YBoard\Exceptions\InternalException;
use YBoard\Library\FileHandler;
use YBoard\Library\MessageQueue;
use YBoard\Library\Text;
use YBoard\Model;

class Files extends Model
{
    public $savePath = false;
    public $maxPixelCount = 50000000;
    public $imgMaxWidth = 1920;
    public $imgMaxHeight = 1920;
    public $thumbMaxWidth = 240;
    public $thumbMaxHeight = 240;

    public function setConfig(array $config) : bool
    {
        $keys = [
            'savePath',
            'maxPixelCount',
            'imgMaxWidth',
            'imgMaxHeight',
            'thumbMaxWidth',
            'thumbMaxHeight',
        ];

        foreach ($keys as $key) {
            if (isset($config[$key])) {
                $this->$key = $config[$key];
            }
        }

        return true;
    }

    public function get(int $fileId)
    {
        $q = $this->db->prepare('SELECT id, folder, name, extension, size, width, height, duration, in_progress,
            has_sound FROM files WHERE id = :file_id LIMIT 1');
        $q->bindValue('file_id', $fileId);
        $q->execute();

        if ($q->rowCount() == 0) {
            return false;
        }
        $row = $q->fetch();

        $file = new File();
        $file->id = $row->id;
        $file->folder = $row->folder;
        $file->name = $row->name;
        $file->extension = $row->extension;
        $file->size = $row->size;
        $file->width = $row->width;
        $file->height = $row->height;
        $file->duration = $row->duration;
        $file->inProgress = $row->in_progress;
        $file->hasSound = $row->has_sound;

        return $file;
    }

    public function processUpload(array $file, bool $skipMd5Check = false) : UploadedFile
    {
        // Verify config
        if (!$this->savePath) {
            throw new InternalException(_('File save path not set'));
        }

        $sendMessage = false;
        $uploadedFile = new UploadedFile();

        // Rename uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadedFile->tmpName)) {
            throw new FileUploadException(_('Cannot move uploaded file'));
        }

        $md5 = md5(file_get_contents($uploadedFile->tmpName));
        $uploadedFile->displayName = pathinfo($file['name'], PATHINFO_FILENAME);
        $uploadedFile->extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (empty($uploadedFile->extension)) {
            throw new FileUploadException(_('The file you uploaded is missing a file extension (e.g. ".jpg").'));
        }

        // If the file already exists, use the old one
        if (!$skipMd5Check) {
            $oldId = $this->getByMd5($md5);
            if ($oldId) {
                $uploadedFile->id = $oldId;

                return $uploadedFile;
            }
        }

        // File type conversions
        if ($uploadedFile->extension == 'jpeg') {
            // JPEG -> JPG
            $uploadedFile->extension = 'jpg';
        }

        if ($uploadedFile->extension == 'gif') {
            // GIF -> JPG or MP4
            $frames = FileHandler::getGifFrameCount($uploadedFile->tmpName);
            if ($frames === 0) {
                throw new InternalException(_('Cannot get the number of GIF frames'));
            }
            $uploadedFile->extension = $frames == 1 ? 'jpg' : 'mp4';
        }

        if ($uploadedFile->extension == 'webm') {
            // WEBM -> MP4
            $uploadedFile->extension = 'mp4';
        }

        if ($uploadedFile->extension == 'mp3') {
            // MP3 -> MP4
            $uploadedFile->extension = 'mp4';
        }

        $uploadedFile->md5[] = $md5;

        $uploadedFile->folder = Text::randomStr(2, false);
        $uploadedFile->name = Text::randomStr(8, false);
        $uploadedFile->size = filesize($uploadedFile->tmpName);

        switch ($uploadedFile->extension) {
            case 'jpg':
                $uploadedFile->destinationFormat = 'jpg';
                break;
            case 'png':
                $uploadedFile->destinationFormat = 'png';
                break;
            default:
                $uploadedFile->destinationFormat = $uploadedFile->extension;
                break;
        }

        // Set file destination names
        $uploadedFile->thumbDestination = $this->savePath . '/' . $uploadedFile->folder . '/t/' . $uploadedFile->name . '.jpg';
        $uploadedFile->destination = $this->savePath . '/' . $uploadedFile->folder . '/o/' . $uploadedFile->name . '.' . $uploadedFile->destinationFormat;

        // Create directories if needed
        if (!is_dir($this->savePath . '/' . $uploadedFile->folder . '/t')) {
            if (!mkdir($this->savePath . '/' . $uploadedFile->folder . '/t', 0775, true)) {
                throw new InternalException(_('Creating a file directory failed'));
            }
        }
        if (!is_dir($this->savePath . '/' . $uploadedFile->folder . '/o')) {
            if (!mkdir($this->savePath . '/' . $uploadedFile->folder . '/o', 0775, true)) {
                throw new InternalException(_('Creating a file directory failed'));
            }
        }

        // Do whatever we do with the uploaded files here.
        switch ($uploadedFile->extension) {
            case 'jpg':
            case 'png':
                $this->limitPixelCount($uploadedFile->tmpName);

                FileHandler::createImage($uploadedFile->tmpName, $uploadedFile->destination, $this->imgMaxWidth,
                    $this->imgMaxHeight, $uploadedFile->destinationFormat);
                FileHandler::createThumbnail($uploadedFile->destination, $uploadedFile->thumbDestination,
                    $this->thumbMaxWidth, $this->thumbMaxHeight, 'jpg');

                chmod($uploadedFile->destination, 0664);
                chmod($uploadedFile->thumbDestination, 0664);

                if ($uploadedFile->extension == 'png') {
                    $sendMessage = MessageQueue::MSG_TYPE_DO_PNGCRUSH;
                }

                if (!FileHandler::verifyFile($uploadedFile->destination) || !FileHandler::verifyFile($uploadedFile->thumbDestination)) {
                    $uploadedFile->destroy();
                    throw new InternalException(_('Saving the uploaded file failed'));
                }

                $uploadedFile->md5[] = md5(file_get_contents($uploadedFile->destination));
                $uploadedFile->md5[] = md5(file_get_contents($uploadedFile->thumbDestination));

                // Get size of the final image
                list($uploadedFile->width, $uploadedFile->height) = getimagesize($uploadedFile->destination);

                break;
            case 'mp4':
                throw new FileUploadException(sprintf(_('Unsupported file type: %s'), $uploadedFile->extension));
                // TODO: Add video support
                break;
            default:
                throw new FileUploadException(sprintf(_('Unsupported file type: %s'), $uploadedFile->extension));
        }

        // Save file to database
        $q = $this->db->prepare("INSERT INTO files (folder, name, extension, size, width, height, in_progress)
            VALUES (:folder, :name, :extension, :size, :width, :height, :in_progress)");
        $q->bindValue('folder', $uploadedFile->folder);
        $q->bindValue('name', $uploadedFile->name);
        $q->bindValue('extension', $uploadedFile->destinationFormat);
        $q->bindValue('size', $uploadedFile->size);
        $q->bindValue('width', $uploadedFile->width);
        $q->bindValue('height', $uploadedFile->height);
        $q->bindValue('in_progress', $uploadedFile->inProgress ? 1 : 0);
        $q->execute();

        $uploadedFile->id = $this->db->lastInsertId();

        if ($sendMessage) {
            $mq = new MessageQueue();
            $mq->send($uploadedFile->id, $sendMessage);
        }

        // Save MD5
        $this->saveMd5List($uploadedFile->id, $uploadedFile->md5);

        return $uploadedFile;
    }

    public function updateFileSize(int $fileId, int $fileSize) : bool
    {
        $q = $this->db->prepare('UPDATE files SET size = :size WHERE id = :file_id LIMIT 1');
        $q->bindValue('size', $fileSize);
        $q->bindValue('file_id', $fileId);
        $q->execute();

        return true;
    }

    public function updateFileInProgress(int $fileId, bool $inProgress) : bool
    {
        $q = $this->db->prepare('UPDATE files SET in_progress = :in_progress WHERE id = :file_id LIMIT 1');
        $q->bindValue('file_id', $fileId);
        $q->bindValue('in_progress', $inProgress);
        $q->execute();

        return true;
    }

    public function saveMd5List(int $fileId, array $md5List) : bool
    {
        $values = '';
        foreach ($md5List as &$md5) {
            $values .= '(' . (int)$fileId . ', ?),';
            $md5 = hex2bin($md5);
        }
        $values = substr($values, 0, -1);

        $q = $this->db->prepare("INSERT IGNORE INTO files_md5 (file_id, md5) VALUES " . $values);
        $q->execute($md5List);

        return $q !== false;
    }

    public function getByMd5(string $md5)
    {
        $q = $this->db->prepare("SELECT file_id FROM files_md5 WHERE md5 = :md5 LIMIT 1");
        $q->bindValue('md5', hex2bin($md5));
        $q->execute();

        if ($q->rowCount() == 0) {
            return false;
        }

        $fileId = (int)$q->fetch()->file_id;
        if ($fileId === 0) {
            return false;
        }

        return $fileId;
    }

    public function deleteOrphans() : bool
    {
        $this->db->query("DELETE FROM files WHERE id NOT IN (SELECT file_id FROM posts_files)");

        return true;
    }

    public function exists(string $name) : bool
    {
        $q = $this->db->prepare("SELECT id FROM files WHERE name = :name LIMIT 1");
        $q->bindValue('name', $name);
        $q->execute();

        return $q->rowCount() != 0;
    }

    protected function limitPixelCount(string $file)
    {
        if ($this->getPixelCount($file) > $this->maxPixelCount) {
            throw new FileUploadException(sprintf(_('Uploaded file exceeds the max pixel count of %s MP'),
                round($this->maxPixelCount / 1000000, 2)));
        }
    }

    protected function getPixelCount(string $file) : int
    {
        $sizes = getimagesize($file);

        if (!$sizes) {
            throw new FileUploadException(_('The file is not a valid image'));
        }

        return $sizes[0] * $sizes[1];
    }
}
