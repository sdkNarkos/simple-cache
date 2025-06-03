

# 🧠 Simple PHP Cache Server

A simple, fast, and extensible cache server written in PHP.  
Uses a lightweight socket-based protocol for communication between clients and server.

---

## 🚀 Features

- Key/value cache with TTL expiration
- Socket-based client/server communication
- Multi-client support via `stream_select()`
- Intuitive PHP client API (`$client->get()`, `$client->set()`, etc.)
- Custom logger support via callback
- Efficient TTL and automatic cleanup
- PHP object serialization support
- Zero external dependencies

---

## 📦 Project Structure

```
.
├── examples/
│   └── run_cache_server.php   # Starts the server
│   └── tmp_test_client.php    # Sample client
├── src/
│   ├── Client/
│   │   └── CacheClient.php
│   │   └── CacheClientQuick.php
│   ├── Manager/
│   │   └── ClientManager.php
│   │   └── StorageManager.php
│   ├── Protocol/
│   │   └── CommandMessage.php
│   │   └── ResponseMessage.php
│   ├── Service/
│   │   └── CacheServer.php
```

---

## ⚙️ Quick Start

### Start the server

```bash
php examples/run_cache_server.php
```

By default, it listens on `127.0.0.1:9000`.

---

### Sample client

```php
use Client\CacheClient;

$client = new CacheClient([
    'host' => '127.0.0.1',
    'port' => 9000,
]);

$client->set('foo', 'bar', 60); // store for 60 seconds
$value = $client->get('foo');

echo "Value = $value\n";
```

---

## ⚙️ Configuration

### Server

```php
$server = new CacheServer([
    'host' => '127.0.0.1',
    'port' => 9000,
    'authKeys' => ['secret-key'],
    'logger' => function($level, $message) {
        echo "[$level] $message\n";
    }
]);
```

### Client

```php
$client = new CacheClient([
    'host' => '127.0.0.1',
    'port' => 9000,
    'authKey' => 'secret-key',
]);
```

---

## 🔒 Security

- Authentication using `authKey`
- No built-in encryption (intended for local or trusted environments)

---

## 📡 Supported Commands

- `set($key, $value, $ttl = 0)`
- `get($key)`
- `getAllKeys()`
- `getRem($key)`
- `exists($key)`
- `expire($key, $ttl)`
- `remove($key)`

- `listSet($key, $value, $ttl = 0)`
- `listGet($key)`
- `listGetAllKeys()`
- `listGetRem($key)`
- `listExists($key)`
- `listExpire($key, $ttl)`
- `listAddFirst($key, $value, $ttl = 0)`
- `listAddLast($key, $value, $ttl = 0)`
- `listGetFirst($key)`
- `listGetLast($key)`
- `listGetRemFirst($key)`
- `listGetRemLast($key)`
- `listRemove($key)`

- `ping()`
- `stats()`

---

## 🧪 Testing

Automated tests are not included yet, but a PHPUnit suite is planned.  
You can test manually using `tmp_test_client.php`.

---

## 👨‍💻 Author

Developed by Narkos

> This project is lightweight, easy to integrate, and can serve as a base for a distributed caching system.

## Disclaimer of Liability

This software is provided "as is", without warranty of any kind.
The author cannot be held liable for any direct or indirect damages resulting from its use.

# FRENCH

# 🧠 Simple PHP Cache Server

Un serveur de cache en PHP simple, rapide et extensible.  
Basé sur un protocole personnalisable et une communication socket légère entre clients et serveur.

---

## 🚀 Fonctionnalités

- Cache clé/valeur avec expiration TTL
- Communication client/serveur via sockets
- Gestion multiclient via `stream_select()`
- API client intuitive (`$client->get()`, `$client->set()`, etc.)
- Logger configurable via callback
- TTL intelligent et expiration automatique
- Support d'objets PHP sérialisés
- Aucune dépendance externe

---

## 📦 Structure du projet

```
.
├── examples/
│   └── run_cache_server.php   # Démarrage du serveur
│   └── tmp_test_client.php    # Exemple de client
├── src/
│   ├── Client/
│   │   └── CacheClient.php
│   │   └── CacheClientQuick.php
│   ├── Manager/
│   │   └── ClientManager.php
│   │   └── StorageManager.php
│   ├── Protocol/
│   │   └── CommandMessage.php
│   │   └── ResponseMessage.php
│   ├── Service/
│   │   └── CacheServer.php
```

---

## ⚙️ Démarrage rapide

### Lancer le serveur

```bash
php examples/run_cache_server.php
```

Par défaut, il écoute sur `127.0.0.1:9000`.

---

### Exemple de client

```php
use Client\CacheClient;

$client = new CacheClient([
    'host' => '127.0.0.1',
    'port' => 9000,
]);

$client->set('foo', 'bar', 60); // stocke pour 60 secondes
$value = $client->get('foo');

echo "Value = $value\n";
```

---

## ⚙️ Configuration

### Pour le serveur

```php
$server = new CacheServer([
    'host' => '127.0.0.1',
    'port' => 9000,
    'authKeys' => ['secret-key'],
    'logger' => function($level, $message) {
        echo "[$level] $message\n";
    }
]);
```

### Pour le client

```php
$client = new CacheClient([
    'host' => '127.0.0.1',
    'port' => 9000,
    'authKey' => 'secret-key',
]);
```

---

## 🔒 Sécurité

- Authentification via `authKey`
- Pas de chiffrement natif (prévu pour un usage local ou protégé)

---

## 📡 Commandes supportées

- `set($key, $value, $ttl = 0)`
- `get($key)`
- `getAllKeys()`
- `getRem($key)`
- `exists($key)`
- `expire($key, $ttl)`
- `remove($key)`

- `listSet($key, $value, $ttl = 0)`
- `listGet($key)`
- `listGetAllKeys()`
- `listGetRem($key)`
- `listExists($key)`
- `listExpire($key, $ttl)`
- `listAddFirst($key, $value, $ttl = 0)`
- `listAddLast($key, $value, $ttl = 0)`
- `listGetFirst($key)`
- `listGetLast($key)`
- `listGetRemFirst($key)`
- `listGetRemLast($key)`
- `listRemove($key)`

- `ping()`
- `stats()`

---

## 🧪 Tests

Les tests automatisés ne sont pas encore inclus, mais une suite PHPUnit est prévue.  
Tu peux tester manuellement avec `tmp_test_client.php`.

---


## 👨‍💻 Auteur

Développé par Narkos

> Ce projet est libre, rapide à intégrer, et peut servir de base à un système distribué.


## Limitation de responsabilité

Ce logiciel est fourni "tel quel", sans garantie d'aucune sorte.
L’auteur ne peut être tenu responsable des dommages directs ou indirects résultant de son utilisation.
