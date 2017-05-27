<?php
/**
* Index
* fabián bravo.
* 26/05/2017
*/

// incluimos las variables de configuración.
include './config/index.php';
// requirimos la clase de Backup.
require_once('./src/backup.php');

// inicializamos la clase con la data de configuración.
$backup = new Backup($config);

// ejecutamos el proceso de respaldo.
$backup->index();
