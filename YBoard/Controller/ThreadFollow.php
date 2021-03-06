<?php
namespace YBoard\Controller;

use YBoard\Controller;
use YBoard\Model\Post;

class ThreadFollow extends Controller
{
    public function add(): void
    {
        $this->validateAjaxCsrfToken();

        if (empty($_POST['thread_id'])) {
            $this->throwJsonError(400);
        }

        $posts = new Post($this->db);
        $thread = $posts->getThread($_POST['thread_id'], false);
        $thread->updateStats('followCount');

        $followedThread = $this->user->threadFollow->get($_POST['thread_id']);
        if ($followedThread === false) {
            $this->user->threadFollow->add($_POST['thread_id']);
        }
    }

    public function remove(): void
    {
        $this->validateAjaxCsrfToken();

        if (empty($_POST['thread_id'])) {
            $this->throwJsonError(400);
        }

        $posts = new Post($this->db);
        $thread = $posts->getThread($_POST['thread_id'], false);
        $thread->updateStats('followCount', -1);

        $followedThread = $this->user->threadFollow->get($_POST['thread_id']);
        if ($followedThread !== false) {
            $followedThread->remove();
        }
    }

    public function markAllRead(): void
    {
        $this->validateAjaxCsrfToken();

        $this->user->threadFollow->markAllRead();
    }
}
