<?php

use Spatie\Browsershot\Browsershot;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Http\Session;

class Controller implements ArrayAccess
{
    protected $db;
    protected $connection;
    protected $request;
    protected $method;

    private $vars = [];

    public function __construct($connection, $request, $method)
    {
        $this->connection = $connection;
        $this->request    = $request;
        $this->method     = $method;
    }

    public function __get($name)
    {
        if ($name === 'session') {
            $session = $this->request->session();

            $this->session = $session;

            return $session;
        }

        throw Exception('Undefined property: '.$name);
    }

    public function offsetExists($key)
    {
        return isset($this->vars[$key]);
    }

    public function offsetGet($key)
    {
        return $this->vars[$key];
    }

    public function offsetSet($key, $value)
    {
        $this->vars[$key] = $value;
    }

    public function offsetUnset($key)
    {
        unset($this->vars[$key]);
    }

    public function beforeRoute()
    {
    }

    public function getTemplatePath($template)
    {
        static $cache = [];
        if (isset($cache[$template])) {
            return $cache[$template];
        }
        $templatePath     = APP_DIR.'/templates/'.implode('/', explode('_', strtolower(substr(static::class, 11)))).'/'.$template;
        $cache[$template] = $templatePath;
        return $templatePath;
    }

    final public function sendBlob($data, $headers = [])
    {
        return new Response(200, $headers, $data);
    }

    final public function sendFile($path, $headers = [], $prefix = '/secure')
    {
        $headers['X-Accel-Redirect'] = $prefix.'/'.ltrim($path, '/');
        return new Response(200, $headers);
    }

    final public function text($text)
    {
        return new Response(200, ['Content-Type' => 'text/plain'], $text);
    }

    final public function json($val, $status = 200)
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($val, JSON_THROW_ON_ERROR));
    }

    final public function redirect($location, $status = 302, $headers = [])
    {
        $headers['Location'] = $location;
        return new Response($status, $headers);
    }

    final public function notFound()
    {
        return new Response(404, [], '<h1>Not Found</h1>');
    }

    final protected function prefix()
    {
        return explode('/', $this->request->path(), 3)[1] ?? null;
    }

    final protected function render($template, $vars = null, $status = 200, $headers = [])
    {
        global $dumps;

        $this->beforeRender();

        $prefix = $this->prefix();
        $app    = [
            'session'    => new SessionProxy($this->session),
            'request'    => $this->request,
            'controller' => substr(static::class, 11),
            'method'     => $this->method,
            'prefix'     => $prefix,
            'config'     => Config::$config,
        ];
        $this->vars['app'] = $app;

        if (DEVELOPMENT && !empty($vars)) {
            $conflict = array_intersect(array_keys($this->vars), array_keys($vars));
            if (!empty($conflict)) {
                throw new Exception('Conflicted variable names: '.implode(', ', $conflict));
            }
        }

        $vars = empty($vars) ? $this->vars : array_merge($this->vars, $vars);

        if ($dumps) {
            $vars['_dumps'] = $dumps;
            $dumps          = [];
        }

        return new Response($status, $headers, App::$renderer->render($this->getTemplatePath($template), $vars));
    }

    final protected function renderPDF($template, $vars = null, $filename = null)
    {
        global $fenom;

        $this->beforeRender();

        $this->vars['session'] = new SessionProxy($this->session());

        if (DEVELOPMENT && !empty($vars)) {
            $conflict = array_intersect(array_keys($this->vars), array_keys($vars));
            if (!empty($conflict)) {
                throw new Exception('Conflicted variable names: '.implode(', ', $conflict));
            }
        }

        $vars = empty($vars) ? $this->vars : array_merge($this->vars, $vars);

        $html = $fenom->fetch($template.'.tpl', $vars);

        $bs = Browsershot::html($html);
        if (Config::$config['node'] ?? null) {
            $node = Config::$config['node'];
            $bs->setNodeBinary($node.'/bin/node');
            $bs->setNpmBinary($node.'/bin/npm');
        }
        $bs->setNodeModulePath(APP_DIR.'/../node_modules');

        $headers = ['Content-Type' => 'application/pdf'];
        if ($filename) {
            $headers['Content-Disposition'] = 'attachment; filename='.$filename;
        }

        return new Response(200, $headers, $bs->pdf());
    }

    final protected function forward($method, $params = [])
    {
        list($cls, $method) = explode('::', 'Controller_'.$method);
        $obj                = new $cls($this->connection, $this->request, $method);
        return call_user_func_array([$obj, $method], $params);
    }

    final protected function isAjax()
    {
        return $this->request->header('X-Requested-With') === 'XMLHttpRequest';
    }

    final protected function remoteIp()
    {
        $req = $this->request;
        foreach (['X-Real-IP', 'X-Forwarded-For'] as $header) {
            $value = $req->header($header);
            if ($value) {
                return $value;
            }
        }
        return $this->connection->getRemoteIp(); // might return empty value when using unix sockets
    }

    final protected function newSession($username)
    {
        $sid           = session_create_id().hash('fnv132', $username.'aeG2gair'.$this->remoteIp());
        $cookie_params = \session_get_cookie_params();

        $this->connection->__header['Set-Cookie'] = [Http::sessionName().'='.$sid
                    .(empty($cookie_params['domain']) ? '' : '; Domain='.$cookie_params['domain'])
                    .(empty($cookie_params['lifetime']) ? '' : '; Max-Age='.($cookie_params['lifetime'] + \time()))
                    .(empty($cookie_params['path']) ? '' : '; Path='.$cookie_params['path'])
                    .(empty($cookie_params['samesite']) ? '' : '; SameSite='.$cookie_params['samesite'])
                    .(!$cookie_params['secure'] ? '' : '; Secure')
                    .(!$cookie_params['httponly'] ? '' : '; HttpOnly'), ];

        $newSession = new Session($sid);
        $oldSession = $this->request->session();
        $newSession->put($oldSession->all());
        $oldSession->flush();

        $this->session = $newSession;

        return $newSession;
    }

    /**
     * Set some common variables for this controller before render.
     */
    protected function beforeRender()
    {
    }
}
