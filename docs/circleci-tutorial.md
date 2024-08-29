#Setting up CircleCI and Behat
If you'd like to run SilverStripe Behat tests on CircleCI, this can be achieved (along with screenshot artifacts on
error) reasonably easily.

The below extract is a full `circle.yml` configuration file that gets the environment setup and running for Behat tests.
Please review it thoroughly as there are a few things you'll need to change for your individual project. Notably:

 * If you already have a behat.yml, you should ensure your local requirements are reflected as the following script will
 overwrite the behat.yml file in order to ensure screenshot failures are stored in a CircleCI-appropriate directory,
 even if you don't store screenshots locally.
 * There is one variable required below (`REPO-NAME`) that you need to fill out yourself depending on the name of your
 repository (CircleCI will check out your code into a sub-directory of the user's homedir based on the repository name).
 * This assumes your Behat fixtures are located under the mysite/ directory. If not, check the `test.override` section
 below.

```
machine:
  php:
    version: 5.6.14
dependencies:
  cache_directories:
    - vendor
    - ~/.composer/cache

  pre:
    # Enable xdebug - this is for code coverage and may not be necessary for you. Remove if you don't need it, it can
    # drastically slow down tests
    - sed -i 's/^;//' ~/.phpenv/versions/$(phpenv global)/etc/conf.d/xdebug.ini

    # We found that some machines have outdated composer versions, so we self-update before running install just in case
    - sudo composer self-update
    - composer install --prefer-source --no-interaction

    # Behat and SilverStripe often require a reasonably large amount of memory, tune to your specific needs
    - echo "memory_limit = 512M" > ~/.phpenv/versions/$(phpenv global)/etc/conf.d/memory.ini

    # Setup the _ss_environment.php file per https://docs.silverstripe.org/en/3.4/getting_started/environment_management
    - |
      cat << 'EOF' > _ss_environment.php
      <?php
      define('SS_DATABASE_SERVER', '127.0.0.1');
      define('SS_DATABASE_CLASS', 'MySQLDatabase');
      define('SS_DATABASE_USERNAME', 'ubuntu');
      define('SS_DATABASE_PASSWORD', '');
      define('SS_ENVIRONMENT_TYPE', 'dev');

      global $_FILE_TO_URL_MAPPING;
      $_FILE_TO_URL_MAPPING['/home/ubuntu/REPO-NAME'] = 'http://localhost:8080/';
      EOF

    # Create Apache vhost config
    - |
      cat << 'EOF' > /etc/apache2/sites-available/website.conf
      Listen 8080
      <VirtualHost *:8080>
        DocumentRoot /home/ubuntu/REPO-NAME
        ServerName localhost
        <FilesMatch \.php$>
          SetHandler application/x-httpd-php
        </FilesMatch>
        <Directory /home/ubuntu/REPO-NAME>
          AllowOverride All
        </Directory>
      </VirtualHost>
      EOF

    # Restart Apache to pickup new config
    - a2ensite website.conf
    - a2enmod rewrite
    - sudo service apache2 restart

    # Get Selenium setup - we currently do this everytime but ideally we could store in the cache_directories and only
    # grab if it doesn't exist to save time - an exercise left to the reader!
    - wget http://selenium-release.storage.googleapis.com/2.52/selenium-server-standalone-2.52.0.jar
    - 'java -jar selenium-server-standalone-2.52.0.jar > /dev/null 2>&1':
        background: true

    # Clear and/or create artifacts directory
    - mkdir $CIRCLE_ARTIFACTS/_behat_results

    # Create silverstipe-cache directory and add group perms
    - if [ -d silverstripe-cache ]; then rm -rf silverstripe-cache; fi
    - mkdir silverstripe-cache
    - chmod 775 silverstripe-cache

    # Create assets directory and add group perms
    - if [ ! -d assets ]; then mkdir assets && chmod 777 assets; fi

    # Setup behat.yml - you will need to merge your current behat.yml into this configuration
    - |
      cat > behat.yml <<EOF
      default:
        paths:
          features: features
          bootstrap: %behat.paths.features%/bootstrap
        formatter:
          name: pretty
          parameters:
            snippets: false
        extensions:
          SilverStripe\BehatExtension\Extension:
            screenshot_path: $CIRCLE_ARTIFACTS/_behat_results/
            ajax_timeout: 10000
          SilverStripe\BehatExtension\MinkExtension:
            base_url:  http://localhost:8080/
            files_path: %%behat.paths.base%%/framework/tests/behat/features/files/
            default_session: selenium2
            javascript_session: selenium2
            selenium2:
              browser: firefox
      EOF

    # Create database and flush cache
    - sake db:build --flush

test:
  override:
    # We override the CircleCI defaults to run both PHPUnit and Behat tests
    - vendor/bin/phpunit

    # You may need to change the @mysite below to the name of your Behat test base
    - vendor/bin/behat --verbose --out=null,$CIRCLE_ARTIFACTS/_behat_results/framework.html,$CIRCLE_ARTIFACTS/_behat_results --format=pretty,html,junit @mysite --tags="~@todo"
```
