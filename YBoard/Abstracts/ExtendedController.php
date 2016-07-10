<?php
namespace YBoard\Abstracts;

use YBoard;
use YBoard\Library\Database;
use YBoard\Library\HttpResponse;
use YBoard\Library\i18n;
use YBoard\Library\TemplateEngine;
use YBoard\Model;

abstract class ExtendedController extends YBoard\Controller
{
    use YBoard\Traits\ErrorPages;
    use YBoard\Traits\Cookies;

    protected $config;
    protected $i18n;
    protected $db;
    protected $boards;
    protected $user;
    protected $locale;

    public function __construct()
    {
        // Load config
        $this->config = require(ROOT_PATH . '/YBoard/Config/YBoard.php');

        // Get a database connection
        $this->db = new Database(require(ROOT_PATH . '/YBoard/Config/Database.php'));

        // Load some data that are required on almost every page, like the list of boards and user data
        $this->boards = new Model\Boards($this->db);

        // Load internalization
        $this->i18n = new i18n(ROOT_PATH . '/YBoard/Locales');

        // Load user
        $this->loadUser();

        // Get locale
        $this->locale = $this->i18n->getPreferredLocale();
        if (!$this->locale) {
            // Fallback
            $this->locale = $this->config['i18n']['defaultLocale'];
        }

        // Set locale
        $this->i18n->loadLocale($this->locale);
    }

    public function __destruct()
    {
        $resourceUsage = round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000,
                2) . ' ms ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB';
        // Only for non-ajax requests
        if (!isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            // Debug: Execution time and memory usage
            echo '<!-- ' . $resourceUsage . ' -->';
        } else {
            //error_log($resourceUsage);
        }
    }

    protected function loadUser()
    {
        $this->user = new Model\User($this->db);

        $sessionId = $this->getLoginCookie();
        if ($sessionId !== false) {
            $load = $this->user->load($sessionId);
            if (!$load) {
                $this->deleteLoginCookie(true);
            }
        } else {
            // Session does not exist
            if ($this->userMaybeBot()) {
                $this->user->createTemporary();
                return false;
            }

            $createUser = $this->user->create();
            if (!$createUser) {
                $this->dieWithError();
            }
            $createSession = $this->user->createSession($this->user->id);
            if (!$createSession) {
                $this->dieWithError();
            }

            $this->setLoginCookie($this->user->sessionId);
        }
    }

    protected function userMaybeBot()
    {
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) // Great way of detecting crawlers!
        {
            return true;
        }

        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return true;
        }

        if (preg_match('/Baiduspider/i', $_SERVER['HTTP_USER_AGENT'])) {
            return true;
        }

        if (preg_match('/msnbot/i', $_SERVER['HTTP_USER_AGENT'])) {
            return true;
        }

        return false;
    }

    protected function dieWithMessage(
        $errorTitle,
        $errorMessage,
        $httpStatus = false,
        $bodyClass = false,
        $image = false
    ) {
        if ($httpStatus && is_int($httpStatus)) {
            HttpResponse::setStatusCode($httpStatus);
        }
        $view = $this->loadTemplateEngine();

        $view->pageTitle = $view->errorTitle = $errorTitle;
        $view->errorMessage = $errorMessage;

        // Support for "state saving", for login etc.
        if (!empty($_POST['redirto'])) {
            $view->redirTo = $_POST['redirto'];
        }

        $view->bodyClass = 'error';
        if (!empty($bodyClass)) {
            $view->bodyClass .= ' ' . $bodyClass;
        }
        if (!empty($image)) {
            $view->errorImageSrc = $image;
        }

        $view->display('Error');
        $this->stopExecution();
    }

    protected function loadTemplateEngine($templateFile = 'Default')
    {
        $templateEngine = new TemplateEngine(ROOT_PATH . '/YBoard/View/', $templateFile);

        foreach ($this->config['view'] as $var => $val) {
            $templateEngine->$var = $val;
        }

        // Increment user page loads only when using the "Default" -template
        if ($templateFile == 'Default') {
            $this->user->statistics->increment('pageLoads');
        }

        $stylesheet = 'ylilauta';
        $altStylesheet = 'ylilauta_dark';
        if ($this->user->preferences->darkTheme) {
            $stylesheet = 'ylilauta_dark';
            $altStylesheet = 'ylilauta';
        }
        $templateEngine->stylesheet = $stylesheet;
        $templateEngine->altStylesheet = $altStylesheet;

        $templateEngine->csrfToken = $this->user->csrfToken;
        $templateEngine->reCaptchaPublicKey = $this->config['reCaptcha']['publicKey'];
        $templateEngine->user = $this->user;
        $templateEngine->boardList = $this->boards->getAll();

        return $templateEngine;
    }

    protected function disallowNonPost()
    {
        if (!$this->isPostRequest()) {
            HttpResponse::setStatusCode(405, ['Allowed' => 'POST']);
            $this->stopExecution();
        }
    }

    protected function isPostRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            return true;
        }

        return false;
    }

    protected function invalidAjaxData()
    {
        HttpResponse::setStatusCode(400);
        $this->jsonMessage(_('Invalid request'), true);
        $this->stopExecution();
    }

    protected function validateCsrfToken($token)
    {
        if (empty($token) || empty($this->user->csrfToken)) {
            return false;
        }

        if ($token == $this->user->csrfToken) {
            return true;
        }

        return false;
    }
    
    protected function validatePostCsrfToken()
    {
        if (!$this->isPostRequest() || empty($_POST['csrf_token']) || !$this->validateCsrfToken($_POST['csrf_token'])) {
            $this->badRequest();
        }
    }

    protected function validateAjaxCsrfToken()
    {
        if (!$this->isPostRequest()) {
            $this->ajaxCsrfValidationFail();
        }

        if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || empty($this->user->csrfToken)) {
            $this->ajaxCsrfValidationFail();
        }

        if ($_SERVER['HTTP_X_CSRF_TOKEN'] == $this->user->csrfToken) {
            return true;
        }

        $this->ajaxCsrfValidationFail();

        return false;
    }

    protected function ajaxCsrfValidationFail()
    {
        HttpResponse::setStatusCode(401);
        $this->jsonMessage(_('Your session has expired. Please refresh this page and try again.'), true);
        $this->stopExecution();
    }

    protected function jsonMessage($str, $error = false)
    {
        echo json_encode(['error' => $error, 'message' => $str]);
    }

    protected function throwJsonError($statusCode, $message = false)
    {
        if ($message) {
            $this->jsonMessage($message, true);
        }

        HttpResponse::setStatusCode($statusCode);
        $this->stopExecution();
    }
}
