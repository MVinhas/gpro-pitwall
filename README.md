# GPRO Assistant

This is a tool created to help players of Grand Prix Racing Online (GPRO). It helps to make calculations for the race, manage the driver and check the car wear. The project is using the GPRO API v2 to fetch data automatically so you do not need to type everything manually.

The code is written in PHP 8.3 without a big framework like Laravel, but it tries to follow good architecture with Services and Repositories.

This project has security and privacy by design. No passwords, no way to do user enumeration, no clear text emails in the database.

## Requirements

To run this application on your machine, you need to have:

* PHP 8.3 or higher
* Composer
* An email service (I recommend `mailcatcher`)
* Extensions for PHP: `curl`, `mbstring`, `sqlite3`
* Optional: `apcu` or `redis` extension if you want better performance for caching

## Installation

1. First you need to clone the repository or download the files to your folder.
2. Open the terminal in the project folder and run composer to install dependencies:
```bash
composer install

```


3. You need to create the configuration file. There is an example file you can copy:
```bash
cp .env.example .env

```


4. Open the `.env` file and edit your settings. It is very important that you put your GPRO API credentials there. If you do not have them, the sync feature will not work.

## Database Setup

The application uses SQLite, so it is a file inside the project, you don't need to install MySQL.

To create the database and fill it with the track data and constants, you need to run the seeder script:

```bash
php bin/seed_tracks.php

```

This will generate the `gpro_pilots.sqlite` file.

5. You will also need to create your own secrets.php file
```bash
cp config/secrets.php.example secrets.php

```
The secrets are...well, secrets, most of them would destroy the FOBY culture of GPRO. I understand this may look like it's defeating the purpose of having the source code of a GPRO tool, but with the basis you may get yourself to the actual formulas.

## Caching

Because the GPRO API has limits on how many requests we can send, the application uses a caching system.

Inside your `.env` file, you can change the `CACHE_DRIVER`.

* Use `array` if you are testing and don't have cache extensions.
* Use `apcu` or `redis` for production. This is better because it saves the data for 1 hour or more, so we don't get "Connection Error" from the API.

## How to use

You can run the application using the PHP built-in server for testing:

```bash
php -S localhost:8000 -t public

```

Then go to your browser at `http://localhost:8000`.

### Features

* **Authentication:** You can register and login. It uses a secure session.
* **Sync Data:** In the menu, there is a button to Sync. This downloads all your data (Office, Driver, Car) at once and saves it to cache.
* **Car Wear:** Calculates the wear of parts based on the driver attributes from API.
* **Strategy:** Helps to plan fuel and tyres for the race.
* **Recruitment:** Calculate the best stats for a new pilot.

## Project Structure

* `src/`: All the classes are here.
* `Controller/`: The pages logic.
* `Service/`: The logic for calculations and API connection.
* `Repository/`: Database access.
* `Security/`: Csrf, email hashing into DB


* `templates/`: The HTML files using Twig.
* `public/`: The entry point `index.php`.
* `bin/`: Scripts to run in command line.

## Notes

If you find a bug, please report it. The code is being refactored to be cleaner and safer. We are trying to move all logic out of controllers and into services to make it easy to test.