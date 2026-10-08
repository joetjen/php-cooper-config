<?php

declare(strict_types=1);

use JOetjen\CooperConfig\Config;

if (!function_exists('cooper_config')) {
    /**
     * The loaded configuration, or the value at `$path` in it: the global
     * shorthand for `Config::all()`/`Config::get()`. Not `config()`,
     * which Laravel owns.
     *
     * @param string|list<string|int>|null $path a dotted path or a list of segments; null for the whole configuration
     * @param mixed $default what to return when `$path` leads nowhere
     * @return mixed the value, or `$default`
     * @throws JOetjen\CooperConfig\NotLoadedError when `Config::load()` has not run
     * @throws InvalidArgumentException on an empty path or segment
     */
    function cooper_config(string|array|null $path = null, mixed $default = null): mixed
    {
        return $path === null ? Config::all() : Config::get($path, $default);
    }
}
