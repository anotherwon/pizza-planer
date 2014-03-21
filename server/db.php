<?php

$TABLES = array(
    "pizzas" => "id",
    "users" => "name"
);

# open db
$controller = new Controller();
$out = Output::getInstance();
$pizza = new Pizza($controller);
$user = new User($controller, $pizza);

$tables = array_keys($TABLES);
foreach ($tables as $table) {
    request($controller->getDB(), $table, $TABLES[$table], $retVal);
}

if (isset($_GET["pizza-users"])) {
    if (!empty($_GET["pizza-users"])) {
        $pizza->getPizzaUsersByID($_GET["pizza-users"]);
    } else {
        $pizza->getPizzaUsers();
    }
}

$http_raw = file_get_contents("php://input");


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
        $pizza->addPizza($obj["name"], $obj["maxpersons"], $obj["price"], $obj["content"]);
    }
    if (isset($_GET["set-ready"])) {
        $user->setReady($obj["id"], $obj["bool"]);
    }
    if (isset($_GET["change-pizza"])) {
        $user->changePizza($obj["id"], $obj["to"]);
    }
    if (isset($_GET["pay"])) {
        if ($controller->checkSecret($obj["secret"])) {
            $user->pay($obj["id"], $obj["bool"]);
        }
    }
    if (isset($_GET["buy-pizza"])) {
        if ($controller->checkSecret($obj["secret"])) {
            $pizza->buyPizza($obj["id"]);
        }
    }
}

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
            $out->addStatus($tag, array("99998", 90,"Failed to create statement"));
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

        if ($output[1]) {
            $this->retVal["status"]["debug"][] = $output;
            $this->retVal["status"]["db"] = "failed";
        }

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

    private $db;

    public function __construct() {

        try {
            $this->db = new PDO("sqlite:pizza.db3");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        } catch (PDOException $ex) {
            $retVal["status"]["db"] = $ex->getMessage();
            die(json_encode($retVal));
        }
    }

    public function checkSecret($secret) {
        $sql = "SELECT * FROM system WHERE key = 'secret'";

        $stm = $this->exec($sql, array());

        $secretTable = $stm->fetch();
        $stm->closeCursor();
        if ($secretTable["value"] == $secret) {
            Output::getInstance()->addStatus("access", array("0", null, "granted"));
            return true;
        }
        Output::getInstance()->addStatus("access", array("99997", 97,"denied"));
        return false;
    }

    private function prepare($sql) {

        $db = $this->getDB();

        try {
            $stm = $db->prepare($sql);
            if ($db->errorCode() != 0) {
                $retVal["status"]["db"] = $db->errorInfo();
                die(json_encode($retVal));
            }
            return $stm;
        } catch (Exception $ex) {
            $retVal["status"]["db"] = $ex->getMessage();
            die(json_encode($retVal));
        }
    }

    private function execute($stm, $args) {
        try {
            $stm->execute($args);
            return $stm;
        } catch (Exception $ex) {
            $retVal["status"]["db"] = $ex->getMessage();
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

class User {

    private $controller;
    private $pizza;

    public function __construct($controller, $pizza) {
        $this->controller = $controller;
        $this->pizza = $pizza;
    }

    function changePizza($userid, $to) {
        $out = Output::getInstance();

        $sql = "UPDATE users SET pizza = :pizza WHERE id = :id";

        $con = $this->controller;

        $stm = $con->exec($sql, array(
            ":pizza" => $to,
            ":id" => $userid
        ));

        $out->addStatus("change-pizza", $stm->errorInfo());
        $out->add("change-pizza", $con->getDB()->lastInsertId());

        $stm->closeCursor();
    }

    function pay($id, $bool) {
        $out = Output::getInstance();

        $sql = "UPDATE users SET paid = :bool WHERE id = :id";

        $con = $this->controller;

        $stm = $con->exec($sql, array(
            ":id" => $id,
            ":bool" => $bool
        ));

        $out->addStatus("pay", $stm->errorInfo());
        $out->add("pay", $con->getDB()->lastInsertId());

        $stm->closeCursor();
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

        $stm->closeCursor();

        $this->pizza->checkPizzaLock($id);
    }

}

class Pizza {

    private $controller;

    public function __construct($controller) {
        $this->controller = $controller;
    }

    public function buyPizza($id) {
        $out = Output::getInstance();
        if ($this->checkPizzaForLock($id)) {

            $sql = "UPDATE pizzas SET bought = 1 WHERE id = :id";

            $stm = $this->controller->exec($sql, array(
                ":id" => $id
            ));

            $out->addStatus("buy-pizza", $stm->errorInfo());
            $out->add("buy-pizza", $this->controller->getDB()->lastInsertId());

            $stm->closeCursor();
            return;
        }

        $out->addStatus("buy-pizza", array("99999", 20, "Pizza is not ready for buy"));
    }

    function addPizza($name, $maxPerson, $price, $content) {

        $sql = "INSERT INTO pizzas(name, maxpersons, price, content) VALUES(:name, :maxpersons, :price, :content)";

        $con = $this->controller;

        $stm = $con->exec($sql, array(
            ":name" => $name,
            ":maxpersons" => $maxPerson,
            ":price" => $price,
            ":content" => $content
        ));

        $out = Output::getInstance();

        $out->addStatus("addpizza", $stm->errorInfo());
        $out->add("pizza", $con->getDB()->lastInsertId());
    }

    function getPizzaUsers() {

        $result = array();

        $pizzas = $this->getPizzas();

        foreach ($pizzas as $pizza) {
            $result[] = array(
                "users" => $this->getPizzaUsersByID($pizza["id"]),
                "id" => $pizza["id"],
                "maxpersons" => $pizza["maxpersons"]
            );
        }

        Output::getInstance()->add("pizzausers", $result);
    }

    function getPizzas() {
        $con = $this->controller;

        $sql = "SELECT * FROM pizzas";

        $stm = $con->exec($sql, array());

        $pizzas = $stm->fetchAll(PDO::FETCH_ASSOC);
        $stm->closeCursor();

        return $pizzas;
    }

    function getPizzaUsersByID($id) {
        $con = $this->controller;

        $sql = "SELECT name FROM users WHERE pizza = :id";

        $stm = $con->exec($sql, array(
            ":id" => $id
        ));
        $users = $stm->fetchAll(PDO::FETCH_ASSOC);

        $stm->closeCursor();

        return $users;
    }
    
    function checkPizzaForLock($pizzaID) {
        $con = $this->controller;
        $out = Output::getInstance();
        $out->add("pizzaID", $pizzaID);
        
        $sql = "SELECT * FROM users WHERE NOT ready AND pizza = :pizza";
        
        $stm = $con->exec($sql, array(
            ":pizza" => $pizzaID
        ));
        $notReadyUser = $stm->fetchAll(PDO::FETCH_ASSOC);
        if ($notReadyUser) {
            $out->addStatus("pizzalock", array("0", null, "notReady"));
            return false;
        }

        $maxPerson = $this->getMaxPerson($pizzaID);
        $currentPersons = $this->getPersonsByPizza($pizzaID);

        if ($maxPerson == $currentPersons) {
            $out->addStatus("pizzalock", array("0", null, "ready"));
            
            return true;
        }

        $out->addStatus("pizzalock", array("0", null, "notReady"));
        return false;
    }

    function checkPizzaLock($userid) {
        $pizzaID = $this->getPizzaFromUserID($userid);

        if ($this->checkPizzaForLock($pizzaID)) {
            $this->lockPizza($pizzaID, true);
        }
    }

    function lockPizza($id, $bool) {
        $sql = "UPDATE pizzas SET lock = :bool WHERE id = :id";

        $con = $this->controller;

        $stm = $con->exec($sql, array(
            ":id" => $id,
            ":bool" => $bool
        ));

        $out = Output::getInstance();

        $out->addStatus("lock-pizza", $stm->errorInfo());
        $out->add("lock-pizza", $con->getDB()->lastInsertId());

        $stm->closeCursor();
    }

    function getMaxPerson($id) {
        $con = $this->controller;

        $sql = "SELECT maxpersons FROM pizzas WHERE id = :id";

        $stm = $con->exec($sql, array(
            ":id" => $id
        ));
        $pizzas = $stm->fetch();
        if (count($pizzas) > 0) {
            return $pizzas[0];
        } else {
            return 0;
        }
    }

    function getPersonsByPizza($pizzaID) {
        $con = $this->controller;

        $sql = "SELECT * FROM users WHERE pizza = :id";

        $stm = $con->exec($sql, array(
            ":id" => $pizzaID
        ));
        $pizzas = $stm->fetchAll(PDO::FETCH_ASSOC);

        if ($pizzas) {
            return count($pizzas);
        }
        return 0;
    }

    function getPizzaFromUserID($id) {
        $con = $this->controller;

        $sql = "SELECT pizza FROM users WHERE id = :id";

        $stm = $con->exec($sql, array(
            ":id" => $id
        ));
        $pizzas = $stm->fetch(); //$stm->fetchAll(PDO::FETCH_ASSOC);
        if (count($pizzas) > 0) {
            return $pizzas[0];
        } else {
            return 0;
        }
    }

    function buy() {
        
    }

}

?>
