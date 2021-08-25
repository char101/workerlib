<?php

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

class Renderer_Fenom extends Renderer
{
    private $fenom;

    public function __construct()
    {
        $this->fenom = $fenom = new Fenom(new Fenom\Provider(APP_DIR.'/templates'));

        $compileDir = TMP_DIR.'/fenom';
        if (!is_dir($compileDir)) {
            mkdir($compileDir, 0700);
        }
        $fenom->setCompileDir($compileDir);
        $fenom->setOptions([
            'auto_reload'   => DEVELOPMENT,
            'force_verify'  => false, // convert undeclared variables to null
            'force_compile' => DEVELOPMENT, // required in development only when testing new tag
            'force_include' => false,
        ]);

        $this->scss = new \ScssPhp\ScssPhp\Compiler();
        $this->scss->setOutputStyle(PRODUCTION ? \ScssPhp\ScssPhp\OutputStyle::COMPRESSED : \ScssPhp\ScssPhp\OutputStyle::EXPANDED);

        $this->parsedown = new Parsedown();

        $this->setFunctions();
        $this->setBlockCompilers();
        $this->setCompilers();
        $this->setBlockFunctions();
        $this->setModifiers();
    }

    public function render($template, $vars = [])
    {
        return $this->fenom->fetchFile($template, $vars);
    }

    public function compileTemplates()
    {
        $compileDir = TMP_DIR.'/fenom';
        $fenom      = $this->fenom;
        $fh         = fopen($compileDir.'lock', 'w');
        if ($fh) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_DIR.'/templates')) as $it) {
                    if ($it->isFile() && $it->getExtension() === 'tpl') {
                        // the template filename has is derived from whatever name is passed to fenom
                        // since we use relative path we also need to use relative path when compiling it
                        $templatePath         = $it->getPathName();
                        $relativeTemplatePath = substr($templatePath, strlen(APP_DIR.'/templates') + 1);
                        $compiledPath         = $compileDir.$fenom->getCompileName($relativeTemplatePath);
                        if (!file_exists($compiledPath) || filemtime($templatePath) > filemtime($compiledPath)) {
                            if (DEVELOPMENT) {
                                echo 'compiling '.$relativeTemplatePath.' to '.$compiledPath."\n";
                            }
                            $fenom->compile($relativeTemplatePath);
                        }
                    }
                }
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        }
    }

    public static function clean()
    {
        $cacheDir = TMP_DIR.'/fenom';
        if (is_dir($cacheDir)) {
            runWithLock($cacheDir.'/clean.lock', function () use ($cacheDir) {
                foreach (scandir($cacheDir) as $file) {
                    if ($file[0] === '.' || $file === 'lock') {
                        continue;
                    }
                    if (is_file($file)) {
                        unlink($cacheDir.'/'.$file);
                    }
                }
            }, 5);
        }
    }

    // {function x=10}
    private function setFunctions()
    {
    }

    // runtime block
    // {function}content{/function}
    private function setBlockFunctions()
    {
        // translate
        $this->fenom->addBlockFunction('t', function ($params, $content, $tpl, $var) {
            return Data::$translations[$var['exam']['lang'].'.translation.'.$content];
        });
    }

    // custom inline tag
    // {tag ...}
    private function setCompilers()
    {
        $fenom = $this->fenom;

        // {date 'd-M-Y'}
        $fenom->addCompiler('date', function ($tokens, $tag) {
            $tpl = $tag->tpl;
            $tpl->parsePlainArg($tokens, $format);
            return 'echo date(\''.$format.'\');';
        });

        $fenom->addCompiler('scssfile', function ($tokens, $tag) {
            $tpl = $tag->tpl;
            $tpl->parsePlainArg($tokens, $path);
            return ' ?>'.$this->scss->compile(file_get_contents(APP_DIR.'/../static/'.$path)).'<?php ';
        });
    }

    // custom block tag
    // {tag}{/tag}
    private function setBlockCompilers()
    {
        $fenom = $this->fenom;

        $fenom->addBlockCompiler('scss', function ($tokenizer, $tag) {}, function ($tokenizer, $tag) {
            return ' ?>'.$this->scss->compile($tag->cutContent()).'<?php ';
        });

        $markdown = function ($tokenizer, $tag) {
            $content = $tag->cutContent();
            $content = preg_replace('/^\s+/m', '', $content); // remove initial whitespace to prevent the text taken as pre
            return ' ?>'.$this->parsedown->text($content).'<?php ';
        };
        $fenom->addBlockCompiler('markdown', function ($tokenizer, $tag) {}, $markdown);
        $fenom->addBlockCompiler('md', function ($tokenizer, $tag) {}, $markdown);
    }

    // {$var|modifier}
    private function setModifiers()
    {
        $fenom = $this->fenom;

        $fenom->addModifier('json', 'json_encode');

        $fenom->addModifier('dump', function ($var) {
            $cloner = new VarCloner();
            $dumper = new HtmlDumper();
            $dumper->setTheme('light');
            $dumper->setDisplayOptions(['maxDepth' => 3, 'maxStringLength' => 500]);
            return $dumper->dump($cloner->cloneVar($var), true);
        });

        $fenom->addModifier('errors', function ($errors) {
            $html = '<div class="ui error message"><ul>';
            foreach ($errors as $err) {
                $html .= '<li>'.htmlentities($err).'</li>';
            }
            $html .= '</ul></div>';
            return $html;
        });

        $fenom->addModifier('title', function ($text) { return ucwords($text); });

        $fenom->addModifier('money', function ($val) {
            if (is_numeric($val)) {
                return 'Rp '.number_format($val);
            }
            return '';
        });

        // format value as date using intl
        // supported value formats: any format accepted by strtotime (YYYY-mm-dd, etc.)
        // epoch
        // DateTime instance
        $fenom->addModifier('intl_date', function ($value, $format = 'dd MMMM yyyy') {
            if (is_a($value, 'DateTime')) {
                $date = $value;
            } elseif (is_numeric($value)) {
                $date = DateTime::createFromFormat('U', $value);
            } else {
                $date = new DateTime($value);
            }
            $fmt = datefmt_create('id_ID', IntlDateFormatter::FULL, IntlDateFormatter::FULL, 'Asia/Jakarta', IntlDateFormatter::GREGORIAN, $format);
            return datefmt_format($fmt, $date);
        });

        $fenom->addModifier('date', function ($value, $format = 'd F Y') {
            if (is_numeric($value)) {
                return date($format, $value);
            }
            return date($format, strtotime($value));
        });
    }
}
