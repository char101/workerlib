<?php

class Renderer_Phug_Optimizer extends Phug\Optimizer
{
    private $renderer;

    public function __construct($options)
    {
        parent::__construct($options);

        $this->renderer = new Phug\Renderer($options);
    }

    public function displayFile($__pug_file, array $__pug_parameters = [])
    {
        static $cache = [];

        if (isset($cache[$__pug_file])) {
            $__pug_cache_file = $cache[$__pug_file];
            $expired          = $this->isExpired($__pug_file);
        } else {
            $expired            = $this->isExpired($__pug_file, $__pug_cache_file);
            $cache[$__pug_file] = $__pug_cache_file;
        }

        if ($expired || !file_exists($__pug_cache_file)) {
            file_put_contents($__pug_cache_file, $this->renderer->compileFile($__pug_file));
        }

        if (isset($this->options['shared_variables'])) {
            $__pug_parameters = array_merge($this->options['shared_variables'], $__pug_parameters);
        }

        if (isset($this->options['globals'])) {
            $__pug_parameters = array_merge($this->options['globals'], $__pug_parameters);
        }

        if (isset($this->options['self']) && $this->options['self']) {
            $self             = $this->options['self'] === true ? 'self' : (string) ($this->options['self']);
            $__pug_parameters = [$self => $__pug_parameters];
        }

        $execution = function () use ($__pug_cache_file, &$__pug_parameters) {
            extract($__pug_parameters);
            include $__pug_cache_file;
        };

        if (isset($__pug_parameters['this'])) {
            $execution = $execution->bindTo($__pug_parameters['this']);
            unset($__pug_parameters['this']);
        }

        $execution();
    }
}
