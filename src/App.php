<?php

use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

function exception_error_handler($severity, $message, $file, $line)
{
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}

class App
{
    public static $renderers = [
        'pug' => Renderer_Phug::class,
        'tpl' => Renderer_Fenom::class,
    ];
    private $worker;
    private $routeCollector;
    private $templateMap = [];

    public function __construct($workerFunc = null)
    {
        $caller  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $appDir  = dirname($caller['file']);
        $appName = basename($appDir);

        define('APP_DIR', $appDir);
        define('APP_NAME', $appName);
        define('TMP_DIR', '/tmp/'.$appName.'/'.ENV);
        if (!is_dir(TMP_DIR)) {
            mkdir(TMP_DIR, 0700);
        }
        // TODO: rotate logs
        define('LOG_DIR', APP_DIR.'/log/'.ENV);
        if (!is_dir(LOG_DIR)) {
            mkdir(LOG_DIR, 0700);
        }

        echo sprintf("ENV: %s APP_DIR: %s\n", ENV, APP_DIR);

        spl_autoload_register(function ($class) {
            $path = APP_DIR.'/classes/'.str_replace('_', DIRECTORY_SEPARATOR, $class).'.php';
            if (file_exists($path)) {
                include $path;
            }
        });

        Worker::$pidFile = sprintf('%s/master.pid', LOG_DIR);
        Worker::$logFile = sprintf('%s/workerman.log', LOG_DIR);
        if (PRODUCTION || UPSTREAM) {
            Worker::$stdoutFile = sprintf('%s/stdout.log', LOG_DIR);
        }

        $this->worker          = $worker          = new Worker(sprintf('unix:///tmp/%s-%s.sock', APP_NAME, ENV));
        $worker->reusePort     = false;
        $worker->protocol      = '\Workerman\Protocols\Http';
        $worker->count         = (PRODUCTION || UPSTREAM) ? (int) (trim(shell_exec('nproc'))) : 2;
        $worker->onWorkerStart = function ($worker) use ($workerFunc) {
            $this->onWorkerStart($worker);
            if ($workerFunc) {
                $workerFunc($this);
            }
            $this->dispatcher = new FastRoute\Dispatcher\GroupCountBased($this->routeCollector->getData());
        };
        $worker->onMessage = [$this, 'onMessage'];

        $this->routeCollector = new FastRoute\RouteCollector(
            new FastRoute\RouteParser\Std(),
            new FastRoute\DataGenerator\GroupCountBased()
        );
    }

    public function run()
    {
        Worker::runAll();
    }

    public function route($path, $handler, $routes = null)
    {
        $router = $this->routeCollector;

        if (!$routes) {
            list($method, $path) = preg_split('/\s+/', $path);
            $method              = explode('|', $method);
            $router->addRoute($method, $path, $handler);
        } else {
            $prefix = $path;
            $class  = $handler;
            foreach ($routes as $path => $handler) {
                list($method, $path) = preg_split('/\s+/', $path);
                $method              = explode('|', $method);
                $path                = preg_replace('@/{2,}@', '/', $prefix.$path);
                $path                = rtrim($path, '/');
                $router->addRoute($method, $path, [$class, $handler]);
            }
        }
    }

    public function scanRoutes($directory)
    {
        $router    = $this->routeCollector;
        $directory = realpath($directory);
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $it) {
            if ($it->isFile() && $it->getExtension() === 'php') {
                $className = 'Controller_'.str_replace(DIRECTORY_SEPARATOR, '_', substr($it->getPathname(), strlen($directory) + 1, -4));
                $cls       = new ReflectionClass($className);

                $prefix = '';
                foreach ($cls->getAttributes(Route::class) as $attr) {
                    $paths = $attr->newInstance()->paths;
                    if (count($paths)) {
                        $prefix = $paths[0];
                    } else {
                        // just Route(), prefix = Controller_A_B_C -> /a/b/c
                        $prefix = '/'.implode('/', explode('_', strtolower(substr($className, 11))));
                    }
                }

                $routes = [];
                foreach ($cls->getMethods() as $method) {
                    foreach ($method->getAttributes(Route::class) as $attr) {
                        $paths      = $attr->newInstance()->paths;
                        $methodName = $method->getName();
                        if (count($paths)) {
                            foreach ($paths as $path) {
                                $parts = preg_split('/\s+/', $path);
                                if (count($parts) == 1) {
                                    if ($parts[0][0] == '/') {
                                        $methods = ['GET'];
                                        $path    = $parts[0];
                                    } else {
                                        $methods = [$parts[0]];
                                        $path    = '/'.implode('-', splitCamelCase($methodName));
                                    }
                                    $routes[] = [$methods, $path, $methodName];
                                } else {
                                    list($methods, $path) = $parts;
                                    $routes[]             = [explode('|', $methods), $path, $methodName];
                                }
                            }
                        } else {
                            // just Route, url of methodName = GET /method-name
                            $path     = '/'.implode('-', splitCamelCase($methodName));
                            $routes[] = [['GET'], $path, $methodName];
                        }
                    }
                }

                foreach ($routes as $route) {
                    list($methods, $path, $methodName) = $route;

                    // normalize multiple //
                    $path = preg_replace('@/{2,}@', '/', $prefix.$path);

                    // if (DEVELOPMENT || TESTING) {
                    //     echo sprintf("[%d] scanRoute: %-20s %-6s %-30s %s\n", getmypid(), $className, implode('|', $methods), $path, $methodName);
                    // }

                    $router->addRoute($methods, $path, [$className, $methodName]);
                }
            }
        }
    }

    public function scanTemplates($directory)
    {
        $templateMap = &$this->templateMap;
        $directory   = realpath($directory);
        $iterator    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $exts        = array_keys(self::$renderers);
        foreach ($iterator as $it) {
            if (!$it->isFile()) {
                continue;
            }
            foreach ($exts as $ext) {
                if ($it->getExtension() === $ext) {
                    $path = $it->getPathname();

                    $templateMap[substr($path, strlen($directory), -(strlen($ext) + 1))] = $path;

                    break;
                }
            }
        }
    }

    public function render($template, $vars = [])
    {
        static $renderers = [];

        if (!isset($this->templateMap[$template])) {
            throw new Exception('Template not found: '.$template);
        }
        $templatePath = $this->templateMap[$template];

        $ext = pathinfo($templatePath, PATHINFO_EXTENSION);

        $cls = self::$renderers[$ext];
        if (!isset($renderers[$cls])) {
            $renderers[$cls] = new $cls();
        }
        return $renderers[$cls]->render($templatePath, $vars);
    }

    public function onMessage($conn, $req)
    {
        $method = $req->method();
        $uri    = $req->uri();

        // remove query string
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        $result = $this->dispatcher->dispatch($method, $uri);

        $status = $result[0];
        switch ($status) {
            case FastRoute\Dispatcher::NOT_FOUND:
            $conn->send(new Response(404, [], '<h1>Not Found: '.$uri.'</h1>'));
            break;
        case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
            $conn->send(new Response(405, [], '<h1>Method Not Allowed</h1>'));
            break;
        case FastRoute\Dispatcher::FOUND:
            $handler = $result[1];
            $vars    = $result[2];

            try {
                if (is_array($handler)) {
                    list($cls, $method) = $handler;

                    $obj = new $cls($this, $conn, $req, $method);

                    // process beforeRoute
                    $resp = $obj->beforeRoute();

                    // if beforeRoute does not generate any response, call the handler
                    if ($resp === null || $resp === true) {
                        $resp = $this->callHandler($conn, $req, [$obj, $method], $vars);
                    }
                } else {
                    $resp = $this->callHandler($conn, $req, $handler, $vars);
                }

                // automatically convert array to json; use new StdClass() to send empty object
                if (is_array($resp) || (is_object($resp) && !($resp instanceof Response))) {
                    $resp = $obj->json($resp);
                }

                $conn->send($resp);

                $sessionId = $req->cookie(Http::sessionName());
                if ($sessionId) {
                    Session::updateTimestamp($sessionId);
                }
            } catch (\Throwable $e) {
                $messages   = [];
                $messages[] = '['.date('r').'] '.$req->method().' '.$req->uri();
                $messages[] = '';
                $messages[] = $e;
                if ($req->method() === 'POST') {
                    $messages[] = '';
                    $post       = $req->post();
                    if (!empty($post)) {
                        $messages[] = 'POST '.var_export($post, true);
                    }
                }
                $session = $req->session()->all();
                if (!empty($session)) {
                    $messages[] = '';
                    $messages[] = 'session = '.var_export($session, true);
                }
                $message = implode("\n", $messages);

                fwrite(STDERR, $message."\n"); // development: log to stderr, production: log to Worker::$stdoutFile

                $conn->send(new Response(500, ['Content-Type' => 'text/plain'], (PRODUCTION || UPSTREAM) ? '<h1>Internal Server Error</h1>' : $message));
            }
            break;
        }
    }

    protected function onWorkerStart($worker)
    {
        chmod(sprintf('/tmp/%s-%s.sock', APP_NAME, ENV), 0777);

        //if (!DEVELOPMENT) {
        foreach (array_values(self::$renderers) as $rendererClass) {
            call_user_func([$rendererClass, 'clean']);
        }
        //}

        Config::load();
        Http::SessionName('sid');
        set_error_handler('exception_error_handler');
        Session::handlerClass(RedisSessionHandler::class, Config::get('session'));

        $controllerDir = APP_DIR.'/classes/Controller';
        if (is_dir($controllerDir)) {
            $this->scanRoutes($controllerDir);
        }

        $templatesDir = APP_DIR.'/templates';
        if (is_dir($templatesDir)) {
            $this->scanTemplates($templatesDir);
        }
    }

    /** Call handler using dependecy injection via parameters */
    protected function callHandler($conn, $request, $handler, $vars)
    {
        static $parameterCache = [];

        if (is_array($handler)) {
            $cacheKey = get_class($handler[0]).'::'.$handler[1];
        } elseif (is_string($handler)) {
            $cacheKey = $handler;
        } elseif ($handler instanceof Closure) {
            $cacheKey = spl_object_hash($handler);
        } else {
            throw new Exception('Unknown handler type: '.$handler);
        }

        if (isset($parameterCache[$cacheKey])) {
            $parameters = $parameterCache[$cacheKey];
        } else {
            $parameters = [];
            $reflect    = is_array($handler) ? new ReflectionMethod($handler[0], $handler[1]) : new ReflectionFunction($handler);
            foreach ($reflect->getParameters() as $param) {
                $opt          = $param->isOptional();
                $parameters[] = [$param->getName(), $opt, $opt ? $param->getDefaultValue() : null];
            }
            $parameterCache[$cacheKey] = $parameters;
        }

        $callParams = [];
        foreach ($parameters as $param) {
            list($name, $isOptional, $default) = $param;
            switch ($name) {
                case 'connection': $callParams = $conn; break;
                case 'request': $callParams[]  = $request; break;
                case 'session': $callParams[]  = $request->session; break;
                case 'get': $callParams[]      = $request->get(); break;
                case 'post': $callParams[]     = $request->post(); break;
                case 'files': $callParams[]    = $request->file(); break;
                default:
                    if (isset($vars[$name])) {
                        $callParams[] = $vars[$name];
                    } elseif (!$isOptional) {
                        return new Response(400, [], '<h1>Bad Request</h1>');
                    } else {
                        $callParams[] = $default;
                    }
                    break;
            }
        }

        return call_user_func_array($handler, $callParams);
    }
}
