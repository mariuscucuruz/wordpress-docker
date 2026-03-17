### Starting Notes

You're a senior Full-Stack PHP developer with extensive practice in WordPress development, and you are tasked with building a brand new WordPress plugin.
Do not rely on any software dependencies to be present on the host computer; instead, run with Docker (docker compose); if commands hang for more than 3 minutes, abort and move on.
Data and data structure can be changed or purged at any time since this project is in early development phase.
Use best practices for Git and Docker.

### Task

Build a WordPress plugin that allows admin users to connect their own Digital Asset Mananegement (DAM) 3rd party provider or social media channel (e.g. Adobe Experience Manager, LinkedIn, etc).

There are a number of supported integrations (in `src/Integrations/{IntegrationName}`) that can be connected by providing the oAuth / API configuration values described in the respective `src/Integrations/{IntegrationName}/config/{integrationname}.php` files (more will be added at later times).

##### Role-based access control:
- ADMIN: can connect an integration by supplying oAuth configuration.
- USER: can use existing connections, but cannot change existing connections or add new ones.

##### User story #1:
As an ADMIN, I will be able to create a connection for any supperted integrations by providing valid oAuth / API details and going through the authentication / authorisation process with a given integration. Action results, error messages and requests details should be stored for debugging purposes. Encrypted tokens and API keys should be stored to database (always encryped, never in plain text).

##### User story #2:
As a USER, I will be able to browse the structure of a **connected integration** (i.e. an integration sucessfully authenticated with the 3rd party provider by an ADMIN). I will be able to recursively browse all directories available to that given connection and individually select which asset to import into my library.


##### Basic entities:
- integration: all directories in `src/Integrations/{IntegrationName}` is an `Integration`.
- settings: user provided `settings` values, as described in `src/Integrations/{IntegrationName}/config/{integrationname}.php` config files.
- connection: a collection of settings for a given integration (i.e. the encrypted `settings` values in database).

##### Example:
Take for instance "LinkedIn" integration.
An ADMIn could click LinkedIn from the list of available integrations and fill in API client and secret which will effectovely become a *connection* (a named LinkedIn connection); the ADMIN should go through the oAuth process to authorise this connection. We'll need to store API connection details, including tokens (access and / or refresh, incl exp dates) to database for persited access.
All users would then be able to use an exiting connection to browse the entire estate available and individually select assets to import into media library. A minimal dashboard should show each of the assets imported in the recent day and week, including info such as file size, mime type, etc.
