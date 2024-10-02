<?php
require_once __DIR__ . '/UniFi-API-client/src/Client.php';
/* There are 3 requred commmand line args, and several optional ones
REQUIRED:
    user (The Unifi user name being used to connect to the Unifi server)
    passsword (The password of the above username)
    url (The Url of the Unifi system connecting to. e.g. https://192.168.0.1)
OPTIONAL:
    version (The version of the Unifi OS. default: 8.0.28
    site (The site name of the Unifi system connecting to. default: default)
ONLYONE:
    you must set EITHER
        rule (This is the name of the Unifi Port Forward rule )
        host (The name of the host whose IP we will use to allow the Port Forward ingress from)
    OR
        file (This is the file in .json format containing an array of rules and hosts. see example.json)
*/
unset($argv[0]);
parse_str(implode('&', $argv), $_requestArgs);

function println(mixed $msg): void
{
    printf("%s\n", $msg);
}

function check_parameter(array &$args, string $name, bool $required = true, string $default = ""): string
{
    $exists = array_key_exists($name, $args);
    if (!$exists && $required) {
        println($name . " is required");
        exit(1);
    } elseif (!$exists || $args[$name] === "") {
        return $default;
    }
    return $args[$name];
}


$controlleruser = check_parameter($_requestArgs, "user");
$controllerpassword = check_parameter($_requestArgs, "password");
$controllerurl = check_parameter($_requestArgs, "url");
$controllerversion = check_parameter($_requestArgs, "version", false, "8.0.28");
$site_id = check_parameter($_requestArgs, "site", false, "default");

// check that either file or the rule/host are set
$file = check_parameter($_requestArgs, "file", false);
$host = check_parameter($_requestArgs, "host", false);
$rule = check_parameter($_requestArgs, "rule", false);

$fileExists = $file !== "";
$hostRuleExists = $host !== "" || $rule !== "";

if ($fileExists && $hostRuleExists) {
    println("You must only supply either file or host/rule");
    exit(1);
}

$hostRules = array();

if ($fileExists) {
    //parse the JSON file into an object Array
    $hostRules = json_decode(file_get_contents($file));
} else {
    //put the host/rule into an object array
    $hostRules[] = (object) ['host' => $host, 'rule' => $rule];
}

$debug = true;

$unifi_connection = new UniFi_API\Client(
    $controlleruser,
    $controllerpassword,
    $controllerurl,
    $site_id,
    $controllerversion
);

function find_rule($portforwards, $rulename)
{
    foreach ($portforwards as $element) {
        if ($rulename == $element->name) {
            return $element;
        }
    }
    return false;
}

while (true) {

    $loginresults = $unifi_connection->login();
    $rules = $unifi_connection->list_portforwarding();
    foreach ($hostRules as $element) {
        $newip = gethostbyname($element->host);
        $rule_to_update = find_rule($rules, $element->rule);
        if ($rule_to_update->src === $newip) {
            println("$element->host name has not changed. skipping");
            continue;
        }
        $rule_to_update->src = $newip;
        $id = $rule_to_update->_id;
        $results = $unifi_connection->custom_api_request("/api/s/default/rest/portforward/$id", 'PUT', $rule_to_update);
        println(json_encode($results, JSON_PRETTY_PRINT));
    }
    println("sleeping for 1hr");
    sleep(3600);
}
