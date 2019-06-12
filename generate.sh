#!/bin/bash
while true; do
    read -p "Do you wish to run dev or test [test|dev]? " devtest
    case $devtest in
        [dev]* ) container="magento19-dev";test=false; break;;
        [test]* ) container="magento19-test";test=true; break;;
        * ) echo "Please answer dev or test.";;
    esac
done
while true; do
    read -p "You have chosen to start ${container}, are you sure [y/n]? " yn
    case $yn in
        [Yy]* ) break;;
        [Nn]* ) exit;;
        * ) echo "Please answer yes or no.";;
    esac
done

composer install

# Prepare environment and build package
docker-compose down
docker-compose up -d --build ${container} selenium

sleep 10

# Copy Files for test container
if [ $test = true ];
then
    docker cp ./extension/. ${container}:/pagantis/
fi

# Install magento and sample data
docker-compose exec ${container} install-sampledata
docker-compose exec ${container} install-magento

# Install modman and enable the link creation
docker-compose exec ${container} modman init
docker-compose exec ${container} modman link /pagantis

# Install n98-magerun to enable automatically dev:symlinks so that modman works
docker-compose exec ${container} curl -O https://files.magerun.net/n98-magerun.phar
docker-compose exec ${container} chmod +x n98-magerun.phar
docker-compose exec ${container} ./n98-magerun.phar dev:symlinks 1

set -e

while true; do
    read -p "Do you want to run full tests battery or only configure the module [full/configure]? " tests
    case $tests in
        [full]* ) break;;
        [configure]* ) break;;
        * ) echo "Please answer full or configure."; exit;;
    esac
done

echo $tests

if [ $tests = "full" ];
then
    export MAGENTO_TEST_ENV=test
    # Run test
    echo magento-basic
    extension/lib/Pagantis/bin/phpunit --group magento-basic
    echo magento-configure-backoffice
    extension/lib/Pagantis/bin/phpunit --group magento-configure-backoffice
    echo magento-product-page
    extension/lib/Pagantis/bin/phpunit --group magento-product-page
    echo magento-buy-unregistered
    extension/lib/Pagantis/bin/phpunit --group magento-buy-unregistered
    echo magento-cancel-buy-unregistered
    extension/lib/Pagantis/bin/phpunit --group magento-cancel-buy-unregistered
    echo magento-register
    extension/lib/Pagantis/bin/phpunit --group magento-register
    echo magento-fill-data
    extension/lib/Pagantis/bin/phpunit --group magento-fill-data
    echo magento-buy-registered
    extension/lib/Pagantis/bin/phpunit --group magento-buy-registered
    echo magento-cancel-buy-registered
    extension/lib/Pagantis/bin/phpunit --group magento-cancel-buy-registered
    echo magento-cancel-buy-controllers
    extension/lib/Pagantis/bin/phpunit --group magento-cancel-buy-controllers

    # Copy Files for test container
    if [ $test = true ];
    then
        # Generate Pakage
        echo magento-package
        extension/lib/Pagantis/bin/phpunit --group magento-package
    fi
else
    export MAGENTO_TEST_ENV=dev
    echo magento-basic
    extension/lib/Pagantis/bin/phpunit --group magento-basic
    echo magento-configure-backoffice
    extension/lib/Pagantis/bin/phpunit --group magento-configure-backoffice
fi

docker-compose exec ${container} ./n98-magerun.phar cache:flush
