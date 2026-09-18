This directory must remain outside public_html.

- database.php contains private database credentials.
- cheatgame.php contains the CHEATGAME API key, webhook secret, and allowed server IP reference.
- Never expose either PHP file through the web server, JavaScript, HTML, logs, screenshots, or public repositories.
- Configure the CHEATGAME dashboard webhook secret from the value stored in cheatgame.php.
- Recommended permissions: directory 750, private PHP files 600 or 640 depending on the PHP/web-server user.
