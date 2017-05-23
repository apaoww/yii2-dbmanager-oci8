Yii2 authManager dbmanager for Oracle Oci8 Driver 
=================================================

Installation
------------

### Install With Composer

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require apaoww/yii2-dbmanager-oci8 "dev-master"
```

or add

```
"apaoww/yii2-dbmanager-oci8": "dev-master"
```

to the require section of your `composer.json` file.

### Install From Archive

Download source at https://github.com/apaoww/yii2-dbmanager-oci8
```
return [
    ...
    'aliases' => [
        '@apaoww/DbManagerOci8' => 'path/to/your/extracted',
        ...
    ]
];
```

Usage
-----

Once the extension is installed, simply modify your application configuration as follows :

```
return [	
	'components' => [
		....
		'authManager' => [
                    'class' => 'apaoww\DbManagerOci8\DbManager', // or use 'yii\rbac\DbManager'
                ],
];
```
