{backup-app}
===================


Backup-app contiene lo necesario para generar respaldos de mysql de 1 o mas bases y sincrolizarlos con los servicios de Amazon S3.

----------
Al clonar el proyecto se debe otorgar permisos de escritura a las carpetas dumps/ y log/
`chmod 775 -R dumps/`
`chmod 775 -R log/`

CronJobs
Se debe crear un cron para ejecutar la app cada cierto tiempo
`0 14 * * * php /var/www/html/{proyecto}/index.php`
