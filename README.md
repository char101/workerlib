# workerlib

## Description

Utility classes for [Workerman](https://github.com/walkor/Workerman/).

## Installation

```
composer require char101/workerlib
```

Create `main.php` as the application entry point:
```php
<?php

require __DIR__.'vendor/autoload.php';

$app = new App(function($app) {
    // Code in here will be run in the `onStart` worker event handler
    // and can be reloaded using Workerman reload command.
    // Include custom PHP files here.
});

$app->run();
```


## Routing

Route can be registered in 3 ways:

### Using the `route` method with a closure

```php
require __DIR__.'/vendor/autoload.php';

$app = new App();

$app->route('/user/{id}', function($id) {
    return ['id' => id];
});

$app->run();
```

### Using the `route` method with a class

`classes/Controller/User.php`

```php
class Controller_User extends Controller
{
    public function view($id)
    {
        return ['id' => $id];
    }
}
```

```php
require __DIR__.'/vendor/autoload.php';

$app = new App();

$app->route('/user', Controller_User::class, [
    'GET /{id}' => 'view',
    'POST /{id}' => 'saveEdit'
]);

$app->run();
```

### Using annotations

`classes/Controller/User.php`

```php
#[Route]
class Controller_User extends Controller
{
    #[Route('GET /{id}']
    public function view($id)
    {
        return ['id' => $id];
    }

    #[Route('POST /{id}')]
    public function saveEdit($id)
    {
        return this->redirect('/user/'.$id);
    }
}
```

### Conventions

* Route handler return types
  * text -> `text/plain`
  * array/object -> `application/json`
  * Response -> HTTP Response

`#[Route]` equals to `#[Route('/class_name')]` for class or `#[Route('GET /method_name')]` for
method.

`#[Route('/url')]` equals to `#[Route('GET /url')]` for method.

## Directory Layout

```
app/
  composer.json
  main.php
  classes/
    Controller/
      User.php
  templates/
    layout.pug
    user/
      login.pug
  vendor/
```

## App

The `App` class is the application instance that initializes and run Workerman.

## Controller

## Template

## Database
    
### SQL

### Migrations

### Database

## Services

### Redis

```php
$redis = RedisDB::instance('cache');
$redis->set('key', 'value');
```

### LDAP

## Development

Auto reload server on file change using [watchexec](https://github.com/watchexec/watchexec):
```
watchexec --restart --no-ignore --exts php,pug,yaml --ignore public -- php main.php reload
```

## Testing

## Deployment
