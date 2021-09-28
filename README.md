# DokuWiki Matrix Notifier
Notify DocuWiki page events via [Matrix](https://matrix.org/)

## Installation

Drop the `matrixnotifier` directory under `lib/plugins/` in the DocuWiki installation. Alternatively zip the `matrixnotifier` directory and then use DocuWiki's *Extension Manager* (Admin menu) to do a manual installation.

## Configuration

The plugin is configured via DocuWiki's *Configuration Settings* (Admin menu).

### Required Settings

Option | Description
------ | -----------
homeserver | the Matrix home server to use, e.g. https://matrix.example.com/
accesstoken | the access token for the Matrix account to use
room | the internal ID for the Matrix room where to post the notifications

> With the standard Matrix client **Element** the accesss token can be found in the user account settings under `Help & About > Advanced > Access Token`. The required internal room ID can be found in the options for the room under `Advanced > Room Information`.

## Credits

Wilhelm/ JPTV.club

This project was loosely forked from the *DokuWiki Discord Notifier* (https://github.com/zteeed/dokuwiki-discord-notifier).
