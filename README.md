~~~markdown
# CacheServer

**CacheServer** is a lightweight, TCP-based in-memory cache server written in PHP. It supports key-value pairs and list data structures with expiration (TTL), designed for simplicity and speed.

---

## Features

- TCP socket server with non-blocking IO  
- Key-value storage with TTL expiration  
- List-based collections with common list operations  
- Authentication via secret key  
- JSON message format with length-prefixed TCP frames  
- Simple and extensible protocol commands  
- Automatic cleanup of expired entries  

---

## Installation

1. Clone the repository:

       git clone https://github.com/yourusername/cacheserver.git
       cd cacheserver

2. Make sure PHP CLI is installed (PHP 7.4+ recommended).  

3. Configure your server options inside the PHP script or configuration file.  

---

## Usage

Start the server:

       php CacheServer.php

Then connect to the TCP port (default 9999) and send JSON messages using the defined protocol.

---

## Protocol Overview

Clients communicate via JSON messages prefixed with a 4-byte length header.

### Commands include:

- `set` / `get` / `remove` for key-value pairs  
- `listSet` / `listGet` / `listAddFirst` etc. for list operations  
- `ping` for health check  
- All commands require an `authKey`  

Refer to the Wiki for detailed protocol and usage examples.

---

## Example Message

```json
{
  "authKey": "your-secret-key",
  "command": "set",
  "key": "user_123",
  "val": {"name": "Alice"},
  "lifetime": 3600
}

---

## Contributing

Contributions are welcome! Please fork the repo and open pull requests.

---

## License

This project is licensed under the MIT License.

---

## Contact

For questions or support, open an issue.
