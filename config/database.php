<?php

class Database{

    public static $connection;

    public static function setUpConnection(){
        if(!isset(Database::$connection)){
            Database::$connection = new mysqli("localhost","root"," ","FMS","3306");
        }
    }

    public static function iud($q){
        Database::setUpConnection();
        Database::$connection->query($q);
    }

    public static function search($q){
        Database::setUpConnection();
        $resultset = Database::$connection->query($q);
        return $resultset;
    }

    // Add a new prepared statement method
    public static function prepare($q) {
        Database::setUpConnection();
        return Database::$connection->prepare($q);
    }

    // Add a method to get the connection
    public static function getConnection() {
        Database::setUpConnection();
        return Database::$connection;
    }

}

?>