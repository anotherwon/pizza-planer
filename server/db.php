<?php

$TABLES = array(
    "pizzas" => "id",
    "users" => "name"
);

# open db
$controller = new Controller();
$out = Output::getInstance();

$retVal["status"] = "nothing to do";

$tables = array_keys($TABLES);
foreach ($tables as $table) {
    request($controller->getDB(), $table, $TABLES[$table], $retVal);
}

$http_raw = file_get_contents("php://input");
$pizza = new Pizza($controller);

if (isset($http_raw) && !empty($http_raw)) {

    $obj = json_decode($http_raw, true);

    if (isset($_GET["add-user"])) {

        if (isset($obj["name"])) {
            $retVal["status"] = $controller->addUser($obj["name"]);
        } else {
            $retVal["error"] = $obj;
        }
    }
    if (isset($_GET["add-pizza"])) {
        $pizza->addPizza($obj["name"], $obj["maxperson"], $obj["price"], $obj["content"]);
    }
    if (isset($_GET["set-ready"])) {
        $pizza->setReady($obj["id"], $obj["bool"]);
    }

    if (isset($_GET["torrent-rename"])) {
        $retVal["status"] = renameTorrent($db, $obj["id"], $obj["name"]);
    }
}



$out->add("old", $retVal);
$out->write();

function getTables($db) {

    $tablesquery = $db->query("SELECT name FROM sqlite_master WHERE type='table';");
    $i = 0;
    $tablesRaw = $tablesquery->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tablesRaw as $table) {

        $tables[$i] = $table['name'];
        $i++;
    }

    $tablesquery->closeCursor();

    return $tables;
}

function request($db, $tag, $searchTag, $retVal) {

    $tagObject = $_GET[$tag];

    if (isset($tagObject)) {

        // options
        if (!empty($tagObject)) {
            $STMT = $db->prepare("SELECT * FROM " . $tag . " WHERE " . $searchTag . " = ?");
            $STMT->execute(array($tagObject));
        } else {
            $STMT = $db->query("SELECT * FROM " . $tag);
        }
        
        $out = Output::getInstance();

        if ($STMT !== FALSE) {

            $out->add($tag, $STMT->fetchAll(PDO::FETCH_ASSOC));
            $out->addStatus($tag, $STMT->errorInfo());
        } else {
            $out->addStatus($tag, "Failed to create statement");
        }
        $STMT->closeCursor();
    }

    return $retVal;
}

class Output {

    private static $instance;
    public $retVal;

    private function __construct() {
        $this->retVal['status'] = array();
        $this->retVal['status']["db"] = "ok";
    }

    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function add($table, $output) {
        $this->retVal[$table] = $output;
    }

    public function addStatus($table, $output) {
        $this->retVal['status'][$table] = $output;
    }

    public function write() {
        
        header("Content-Type: application/json");
        header("Access-Control-Allow-Origin: *");
        # Rückmeldung senden
        if (isset($_GET["callback"]) && !empty($_GET["callback"])) {
            $callback = $_GET["callback"];
            echo $callback . "('" . json_encode($this->retVal, JSON_NUMERIC_CHECK) . "')";
        } else {
            echo json_encode($this->retVal, JSON_NUMERIC_CHECK);
        }
    }

}

class Controller {

    public $db;

    public function __construct() {

        try {
            $this->db = new PDO("sqlite:pizza.db3");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        } catch (Exception $ex) {
            $retVal["status"] = $ex->getMessage();
            die(json_encode($retVal));
        }
    }

    private function prepare($sql) {

        $db = $this->getDB();

        try {
            $stm = $db->prepare($sql);
            if ($db->errorCode() != 0) {
                $retVal["status"] = $db->errorInfo();
                die(json_encode($retVal));
            }
            return $stm;
            
        } catch (Exception $ex) {
            $retVal["status"] = $ex->getMessage();
            die(json_encode($retVal));
        }
    }

    private function execute($stm, $args) {
        try {
            $stm->execute($args);
            return $stm;
        } catch (Exception $ex) {
            $retVal["status"] = $ex->getMessage();
            die(json_encode($retVal));
        }
    }

    public function exec($sql, $args) {
        $stm = $this->prepare($sql);
        return $this->execute($stm, $args);
    }

    public function addUser($name) {

        $sql = "INSERT INTO users(name) VALUES(:name)";

        $stm = $this->exec($sql, array(
            ":name" => $name
        ));

        $out = Output::getInstance();
        
        
        $out->addStatus("user", $stm->errorInfo());
        $out->add("user", $this->getDB()->lastInsertId());
        
        $stm->closeCursor();
    }

    public function getUser($id) {

        $sql = "SELECT * FROM users WHERE name = :id";

        $stm = $this->prepare($sql);
        $stm = $this->execute($stm, array(
            ":id" => $id
        ));

        $output = Output::getInstance();

        $output->addStatus("users", $stm->errorInfo());
        $output->add("users", $stm->fetchAll(PDO::FETCH_ASSOC));

        $stm->closeCursor();
    }

    /**
     * 
     * @return PDO database
     */
    public function getDB() {

        return $this->db;
    }

}

class Pizza {

    private $controller;

    public function __construct($controller) {
        $this->controller = $controller;
    }

    function addPizza($name, $maxPersons, $price, $content) {

        $sql = "INSERT INTO pizzas(name, maxperson, price, content) VALUES(:name, :maxperson, :price, :content)";

        $con = $this->controller;

        $stm = $con->exec($sql, array(
            ":name" => $name,
            ":maxperson" => $maxPersons,
            ":price" => $price,
            ":content" => $content
        ));

        $out = Output::getInstance();

        $out->addStatus("addpizza", $stm->errorInfo());
        $out->add("pizza", $con->getDB()->lastInsertId());
    }

    function changePizza($userid, $to) {
        
    }

    function pay() {
        
    }

    function setReady($id, $bool) {
        $sql = "UPDATE users SET ready = :bool WHERE id = :id";
        
        $con = $this->controller;
        
        $stm = $con->exec($sql, array(
           ":id" => $id,
            ":bool" => $bool
        ));
        
        $out = Output::getInstance();

        $out->addStatus("set-ready", $stm->errorInfo());
        $out->add("set-ready", $con->getDB()->lastInsertId());
    }

    function buy() {
        
    }

}

?>
