## WordPress Docker Development Environment
A streamlined, production-ready local development environment for WordPress. This setup provides a containerized stack featuring WordPress, Nginx as a reverse proxy, and MariaDB, allowing you to build themes and plugins without installing PHP or databases locally.

### Prerequisites
Before you begin, ensure you have the following installed on your machine:

##### Docker Desktop (or Docker Engine on Linux)

##### Docker Compose (included with Docker Desktop)

### Note:
On Windows, it is highly recommended to use WSL2 for significantly better file system performance.

---


## Quick Start
Follow these steps to get your environment up and running:

### 1. Clone the repository

```bash
git clone https://github.com/mariuscucuruz/wordpress-docker.git
cd wordpress-docker
```

### 2. Launch the containers

Run the following command to build and start the stack in "detached" mode:

```bash
docker compose up -d
```

### 3. Access WordPress

Once the containers are healthy, open your browser and navigate to:

URL: http://localhost:8080 (or your configured damimporter.local)

Database: Handled internally by the db service.

## Project Structure

```
Directory/File	Description

/nginx	Contains proxy.conf and Nginx configurations.
/wp-content	(Optional) Map this to your local folder to develop themes/plugins.
docker-compose.yml	The orchestration file for all services.
.env	Define your DB credentials and WP version here.
```

### Common Commands

Stop the environment:

```bash
docker compose stop
```

Shut down and remove containers/networks:

```bash
docker compose down
```

View live logs (useful for debugging PHP errors):

```bash
docker compose logs -f wordpress
```

Access the WordPress CLI:

```bash
docker compose exec wordpress bash
```

### Configuration (SSL & Domains)

If you are using the damimporter.local domain as defined in the Nginx config:

#### Hosts File:
Add 127.0.0.1 damimporter.local to your /etc/hosts (Mac/Linux) or C:\Windows\System32\drivers\etc\hosts (Windows).

#### SSL:
Ensure your certificates are placed in the directory mapped within the docker-compose.yml (usually ./ssl).

Would you like me to help you draft the .env.example file so users know exactly which variables to set for their database passwords?
