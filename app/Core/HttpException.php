<?php
namespace Omps\Core;

/**
 * Thrown to stop a request with an HTTP status and a translated message key.
 */
class HttpException extends \RuntimeException
{
    /** @var int */
    public $status;
    /** @var string */
    public $errorCode;
    /** @var array */
    public $params;

    public function __construct($status, $errorCode, $messageKey = '', array $params = array())
    {
        $this->status = (int) $status;
        $this->errorCode = (string) $errorCode;
        $this->params = $params;
        parent::__construct($messageKey === '' ? 'error.' . $errorCode : $messageKey);
    }

    public static function notFound()
    {
        return new self(404, 'not_found');
    }

    public static function forbidden()
    {
        return new self(403, 'forbidden');
    }

    public static function unauthorized()
    {
        return new self(401, 'unauthorized');
    }

    public static function bad($messageKey, array $params = array())
    {
        return new self(400, 'bad_request', $messageKey, $params);
    }

    public static function conflict($messageKey, array $params = array())
    {
        return new self(409, 'conflict', $messageKey, $params);
    }
}
