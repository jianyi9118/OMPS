<?php
namespace Omps\Core;

/**
 * HTTP response. Handlers return it; App::send() writes it, so tests can inspect it.
 */
class Response
{
    /** @var int */
    public $status = 200;
    /** @var array */
    public $headers = array();
    /** @var string[] raw Set-Cookie header values */
    public $cookies = array();
    /** @var string */
    public $body = '';
    /** @var string|null file streamed instead of the body */
    public $file = null;
    /** @var mixed decoded data for JSON responses (tests) */
    public $data = null;

    public function __construct($body = '', $status = 200, array $headers = array())
    {
        $this->body = (string) $body;
        $this->status = (int) $status;
        $this->headers = $headers;
    }

    public static function html($html, $status = 200)
    {
        return new self($html, $status, array('Content-Type' => 'text/html; charset=utf-8'));
    }

    /** Standard API envelope: {ok: true, data: ...} */
    public static function json($data, $status = 200)
    {
        $payload = array('ok' => $status < 400, 'data' => $data);
        $r = new self(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $status, array('Content-Type' => 'application/json; charset=utf-8'));
        $r->data = $payload;
        return $r;
    }

    /** {ok: false, error: {code, message, fields}} */
    public static function error($code, $message, $status = 400, array $fields = array())
    {
        $payload = array('ok' => false, 'error' => array('code' => $code, 'message' => $message));
        if ($fields) {
            $payload['error']['fields'] = $fields;
        }
        $r = new self(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $status, array('Content-Type' => 'application/json; charset=utf-8'));
        $r->data = $payload;
        return $r;
    }

    /** Send a stored file. Shown inline only for types that are safe in the browser; others download. */
    public static function file($path, $downloadName, $mime, $inline = false)
    {
        $safeInline = $inline && preg_match('#^(image/(png|jpeg|gif|webp|bmp)|application/pdf|video/mp4)$#', (string) $mime) === 1;
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $downloadName);
        $r = new self('', 200, array(
            'Content-Type' => $mime !== '' ? (string) $mime : 'application/octet-stream',
            'Content-Disposition' => ($safeInline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode((string) $downloadName),
            'Content-Length' => (string) filesize($path),
        ));
        $r->file = $path;
        return $r;
    }

    public static function redirect($url, $status = 302)
    {
        return new self('', $status, array('Location' => $url));
    }

    public function header($name, $value)
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withCookie($cookieHeader)
    {
        $this->cookies[] = $cookieHeader;
        return $this;
    }

    public function send()
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
            foreach ($this->cookies as $c) {
                header('Set-Cookie: ' . $c, false);
            }
        }
        if ($this->file !== null) {
            readfile($this->file);
            return;
        }
        echo $this->body;
    }
}
