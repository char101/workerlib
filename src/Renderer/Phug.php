<?php

use ScssPhp\ScssPhp\Compiler as Scss;

class Renderer_Phug extends Renderer
{
    private $scss;
    private $optimizer;

    public function __construct()
    {
        $cacheDir = TMP_DIR.'/pug';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700);
        }

        $this->scss = $scss = new Scss();
        $scss->setOutputStyle(DEVELOPMENT ? \ScssPhp\ScssPhp\OutputStyle::EXPANDED : \ScssPhp\ScssPhp\OutputStyle::COMPRESSED);

        $this->optimizer = new Renderer_Phug_Optimizer([
            'paths'   => [APP_DIR.'/templates'],
            'filters' => [
                'scss' => function ($text) {
                    return $this->scss->compileString($text)->getCss();
                },
            ],
            'keywords' => [
            ],
            'php_token_handlers' => [T_VARIABLE => null], // throw exception for undefined variable
            'on_output'          => function ($event) {
                $event->setOutput("<?php namespace pug;\n ?>".$event->getOutput());
            },
            'cache_dir'          => $cacheDir,
            'up_to_date_check'   => DEVELOPMENT, // if set to false false and the cache file does not exist it will fail
            'enable_profiler'    => false,
            'execution_max_time' => -1,
            'memory_limit'       => -1,
        ]);
    }

    public function render($template, $vars = [])
    {
        return $this->optimizer->renderFile($template, $vars);
    }

    public static function clean()
    {
        $cacheDir = TMP_DIR.'/pug';
        if (is_dir($cacheDir)) {
            runWithLock($cacheDir.'/clean.lock', function () use ($cacheDir) {
                foreach (scandir($cacheDir) as $file) {
                    if ($file[0] === '.' || $file === 'lock') {
                        continue;
                    }
                    $path = $cacheDir.'/'.$file;
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }, 5);
        }
    }
}
