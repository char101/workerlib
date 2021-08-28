# workerlib

## Description

Utility classes for [Workerman](https://github.com/walkor/Workerman/).

## Requirements

* PHP >= 8.0
* event extension
* redis extension

## Installation

Set minimum stability to `dev` in `composer.json`.

If there is no existing `composer.json`:

```sh
echo '{"minimum-stability": "dev"}' > composer.json
```

Or add to existing `composer.json`:

```json
{
    "minimum-stability": "dev"
}
```

Install the package:

```sh
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

Configure application:

```sh
cp vendor/char101/workerlib/config.yaml .
```

Open `config.yaml` and configure the values.

## Running the Server

Running the server:

```sh
php main.php start
```

Reloading the code:

```sh
php main.php reload
```

NOTE: reloading only works for code specified inside the _closure_ given to `new
App`.

Restarting the code

```sh
php main.php restart
```

Restarting will also reload the code change in `main.php`.

## Routing

Route can be registered in 3 ways:

### Using the `route` method with a closure

```php
<?php

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

Create new controller in `classes\controller\[Class].php` or
`Controller\[Class].php`.

## Template

Create new template in `templates\[controller_name_in_snake_case].pug`.

## Database

Configure `PDO` URL in `config.yaml`.

### SQL

Create `[Controller].sql` in the same directory as `[Controller].php` then load
the `SQLLoader` service using `$sql` parameter.

`classes/Controller/User.php`:
```php
<?php

class Controller_User extends Controller
{
    public function index($sql)
    {
        return $sql->users();
    }
}
```

`classes/Controller/User.sql`:
```sql
--: users: all
SELECT * FROM user
```

The format of `SQL` identifier is `--: {name}: {return type}`. `{return type}`
is optional, if not specified it will default to `execute`. The available
`{return type}`s refers to the method of [`DB`](https://github.com/char101/workerlib/blob/master/src/DB.php) class.

* `execute`: execute the statement and returns the `Statement` object
* `one`: returns a scalar value of the first column of the first row
* `row`: returns a single row
* `col`: returns the values of the first column of all rows
* `all`: returns all rows
* `map`: returns all rows as associated array with the first column as the array
  key

### Helper Methods

* `insert`: `$db->insert('table', ['col' => 'value'], 'returning id')`;
* `update`: `$db->update('table', ['col' => 'value'], ['where' => 'value'])`;
* `delete`: `$db->update('table', ['where' => 'value'], 'returning *')`;
* `list`: `$db->list([1, 2, 3])`;
* `raw`: `['created' => DB::raw('CURRENT_TIMESTAMP')]`

### Migrations

Not available yet.

## Other Services

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
