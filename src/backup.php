<?php
/**
* Backup class
* fabián bravo.
* 26/05/2017
*/

class Backup {

  public $config_db = array();
  public $s3 = array();
  public $path = '';

  public function __construct( $data ){

    $this->config_db = $data;
    $this->s3 = $data['s3'];
    $this->path = $this->config_db['path_draft'].$this->config_db['path_dumps'];

  }

  /**
  * @index(): inicia el proceso de respaldos
  */
  public function index(){

    // generamos los backups
    $this->backup($this->config_db['databases']);

  }

  /**
  * @backup(): función encargada de los procesos de respaldo.
  */
  private function backup($db){

    // registramos el log
    $this->_log('Inicio de respaldos...');
    // total de bases a respaldar
    $total_db = count($db);
    // recorremos el array de databases
    for ($i = 0; $i < $total_db; $i ++) {
      // realizamos la conexión a la bd
      $connect = $this->connect_db($db[$i]['db_host'], $db[$i]['user'], $db[$i]['pass'], $db[$i]['db_name']);

      if ($connect) {
        // registramos el log
        $this->_log('Conexión a DB correcta.');
        // obtenemos las tablas de a db
        $tables = $this->get_tables();
        if($tables){
          // realizamos el proceso de respaldo
          $this->_dump($tables,$db[$i]['db_name']);
        }else{
          // registramos el log de error
          $this->_log('No se encontraron tablas a respaldar');
        }
      } else {
        // registramos el log de error
        $this->_log('Error en la conexión a base de datos');
      }
    }
  }

  /**
  * @_dump(): genera el archivo .sql de la base de datos y lo guarda en la carpeta dumps.
  */
  private function _dump($tables, $db_name) {

    // variables de retorno con el esquema de la db
    $return  ='';
    // recorremos las tablas
  	foreach($tables as $table){

      // seleccionamos la información
  		$result = mysql_query('SELECT * FROM '.$table);
  		$num_fields = mysql_num_fields($result);

      // armamos el script sql
  		$return.= 'DROP TABLE '.$table.';';
  		$row2 = mysql_fetch_row(mysql_query('SHOW CREATE TABLE '.$table));
  		$return.= "\n\n".$row2[1].";\n\n";

  		for ($i = 0; $i < $num_fields; $i++){
  			while($row = mysql_fetch_row($result)){
  				$return.= 'INSERT INTO '.$table.' VALUES(';
  				for($j=0; $j < $num_fields; $j++){
  					$row[$j] = addslashes($row[$j]);
  					$row[$j] = ereg_replace("\n","\\n",$row[$j]);
  					if (isset($row[$j])) { $return.= '"'.$row[$j].'"' ; } else { $return.= '""'; }
  					if ($j < ($num_fields-1)) { $return.= ','; }
  				}
  				$return.= ");\n";
  			}
  		}
  		$return.="\n\n\n";
  	}

  	// creamos el nombre del dump
    $dump_name = '/db-backup-' .$db_name. '-'. date('Y-m-d-H-i-s');

    // guardamos el esquema en la carperta asignada
  	$handle = file_put_contents($this->path.$dump_name .'.sql' ,$return);
    // registramos el log
    $this->_log('Respaldo creado con éxito :'.$dump_name .'.sql');

    if($handle) {
      // comprimimos el dump anterior
      $compr = $this->_dump_compr($dump_name);
      // enviamos el dump comprimido a S3
      $s3_upload  = $this->s3_backup($dump_name, $db_name);

      if($s3_upload){
        // eliminamos el dump local
        $this->delete_backups($dump_name);
      }
    }
  }

  /**
  * @s3_backup(): se encarga de enviar al backup a S3
  */
  private function s3_backup($dump_name, $db_name) {
    // incluimos el SDK de AWS S3
    require_once ('./include/S3.php');
    // inicializamos S3
    $s3 = new S3($this->s3['access_key'], $this->s3['secret_key'], false);
    // asignamos la ruta del archivo a subir
    $uploadedFile = $this->path.$dump_name .".tar.gz";
    // enviamos el archivo a S3
    if ($s3->putObjectFile($uploadedFile, $this->s3['bucket_name'], basename($uploadedFile), S3::ACL_PRIVATE)) {
        // enviamos un email de notificación
        //$this->send_email($db_name, $dump_name);
        // registramos el log
        $this->_log('Upload S3 Aws con éxito :'.$dump_name .".tar.gz");
        return true;
    } else {
      // registramos el log de error
      $this->_log('Upload S3 Aws error :'.$dump_name .".tar.gz");
      return false;
    }

  }

  /**
  * #_dump_compr(): comprime el dump para ser enviado a S3.
  */
  private function _dump_compr($dump_name) {

    $file_name = $dump_name.'.sql';
    $cmd = 'tar -czvf '. $this->path.''.$dump_name.'.tar.gz '. $this->path.'/'.$file_name;
    $this->_log('Compresión correcta :'.$dump_name .".tar.gz");
    return shell_exec($cmd);

  }

  /**
  * @delete_backups(): elimina el backup generado.
  */
  private function delete_backups($dump_name) {

    $cmd1 = 'rm '. $this->path .'/'.$dump_name. '.sql';
    $cmd2 = 'rm '. $this->path .'/'.$dump_name. '.tar.gz';

    shell_exec($cmd1);
    shell_exec($cmd2);

  }
  /**
  * @connect_db(): realiza la conexiòn a la base de datos.
  */
  private function connect_db($host, $user, $pass = '', $db_name) {
    // realizamos la conexión a la db
    $link = mysql_connect($host, $user, $pass);

    if($link){
      // conexión con éxito
      mysql_select_db($db_name);
      $response = true;
    } else {
      // error al conectarse
      $response = false;
    }
    return $response;
  }

  /**
  * @get_tables(): retornas las tablas de la base conectada.
  */
  private function get_tables(){
    // creamos el array que tendra todas nuestra tablas
    $tables = array();
		$result = mysql_query('SHOW TABLES');

		while($row = mysql_fetch_row($result)){
			$tables[] = $row[0];
		}
    return $tables;

  }

  private function send_email($db_name, $dump_name) {

    $mensaje = "Se ha completado el respaldo de <b>".$db_name."</b> el ".date('d-m-Y a las H:i:s').
               "<br>nombre archivo: ".$dump_name.".tar.gz en Amazon S3";

    // Enviarlo
    mail('fabian_bravo@live.com', 'Backup '.$db_name, $mensaje);

  }
  private function _log($msg) {
    // guarda los log de generaciòn de respaldo
    $fp = fopen(realpath( '.' ).'/log/log_'.date("Ymd").'.log', 'a');
    fwrite($fp, date("H:i")." -> ".$msg."\n");
    fclose($fp);
  }
}
