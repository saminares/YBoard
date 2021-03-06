<?php
namespace YBoard\Controller\Cli;

use YBoard\Model\Board;
use YBoard\Model\File;
use YBoard\Model\Post;
use YBoard\Model\Thread;
use YBoard\Model\User;
use YBoard\Model\UserSession;

class Cleanup extends AbstractCliDatabase
{
    public function deleteOldFiles(): void
    {
        File::deleteOrphans($this->db);

        $glob = glob($this->config['files']['savePath'] . '/*/*/*.*');
        $i = 1;
        $count = 0;
        foreach ($glob AS $file) {
            if ($i % 1000 == 0) {
                echo '.';
            }
            ++$i;

            $fileName = pathinfo($file, PATHINFO_FILENAME);
            if (File::exists($this->db, $fileName)) {
                continue;
            }

            unlink($file);
            if (!QUIET) {
                echo "\n" . $file . " deleted";
            }
            ++$count;
        }

        if (!QUIET) {
            echo "\n\n" . $count . " files deleted\n";
        }
    }

    public function deleteOldPosts(): void
    {

        $threads = [];
        foreach (Board::getAll($this->db) as $board) {
            if (!$board->inactiveHoursDelete) {
                continue;
            }

            $threads = array_merge($threads, Thread::getOld($this->db, $board->id, $board->inactiveHoursDelete));
        }

        if (!empty($threads)) {
            Post::deleteMany($this->db, $threads);
        }

        if (!QUIET) {
            echo count($threads) . " threads deleted\n";
        }
    }

    public function deleteOldUsers(): void
    {
        // Expire old sessions
        $expiredSessions = UserSession::getExpiredIds($this->db);
        if (!empty($expiredSessions)) {
            UserSession::destroyMany($this->db, $expiredSessions);
        }

        // Delete unusable user accounts
        $unusable = User::getUnusable($this->db);
        if (!empty($unusable)) {
            User::deleteMany($this->db, $unusable);
        }

        if (!QUIET) {
            echo count($expiredSessions) . " expired sessions deleted\n";
            echo count($unusable) . " users deleted\n";
        }
    }
}
