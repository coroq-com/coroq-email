<?php
namespace Coroq;

class Email {
  protected $_fields = [];
  protected $_body = "";

  public function __construct() {
  }

  public function getHeader($pos) {
    return $this->_fields[$pos];
  }

  public function setHeader($pos, $name, $value) {
    $this->validateHeader($name, $value);
    $name = $this->formatName($name);
    $this->_fields[$pos] = [$name, $value];
  }

  public function addHeader($name, $value) {
    $this->validateHeader($name, $value);
    $name = $this->formatName($name);
    $this->_fields[] = [$name, $value];
    return count($this->_fields) - 1;
  }

  public function validateHeader($name, $value) {
    $joined = $name . join("", (array)$value);
    if (!preg_match("//u", $joined)) {
      throw new \RuntimeException("Non-UTF-8 character in mail header");
    }
    if (preg_match("/[\r\n]/u", $joined)) {
      throw new \RuntimeException("CR/LF in mail header");
    }
  }

  public function removeHeader($pos) {
    unset($this->_fields[$pos]);
  }

  public function findHeader($name, $pos = 0) {
    $name = $this->formatName($name);
    $count = count($this->_fields);
    while ($pos < $count) {
      if ($this->_fields[$pos][0] == $name) {
        return $pos;
      }
      ++$pos;
    }
    return false;
  }

  public function formatName($name) {
    $name = ucwords(strtolower(str_replace("-", " ", $name)));
    return str_replace(" ", "-", $name);
  }

  public function getHeaderValue($name) {
    $pos = $this->findHeader($name);
    if ($pos === false) {
      return null;
    }
    $line = $this->getHeader($pos);
    return $line[1];
  }

  public function getHeaderValues($name) {
    $values = [];
    $pos = 0;
    while (true) {
      $pos = $this->findHeader($name, $pos);
      if ($pos === false) {
        break;
      }
      $line = $this->getHeader($pos);
      $values[] = $line[1];
      ++$pos;
    }
    return $values;
  }

  public function getAddressListAddresses($name) {
    $addrs = $this->getHeaderValues($name);
    return array_map(function($addr) {
      if (is_array($addr)) {
        return $addr[1];
      }
      return $addr;
    }, $addrs);
  }

  public function getFromAddresses() {
    return $this->getAddressListAddresses("from");
  }

  public function getToAddresses() {
    return $this->getAddressListAddresses("to");
  }

  public function getReturnPath() {
    $pos = $this->findHeader("return-path");
    if ($pos === false) {
      return null;
    }
    return trim($this->_fields[$pos][1], "<>");
  }

  public function getBody() {
    return $this->_body;
  }

  public function setBody($body) {
    if (!preg_match("//u", $body)) {
      throw new \RuntimeException("Non-UTF-8 character in mail body");
    }
    $this->_body = $body;
  }

  public function string() {
    $body = preg_replace("/(\r\n|\r|\n)/u", "\r\n", $this->_body);
    $body = base64_encode($body);
    $body = chunk_split($body);
    if (!$this->findHeader("date")) {
      $this->addHeader("date", time());
    }
    if (!$this->findHeader("message-id")) {
      $this->addHeader("message-id", $this->newMessageId());
    }
    $head = $this->_fields;
    $head = $this->combineHeader($head);
    $head = $this->encodeHeader($head);
    $mimeHead = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    return $head . $mimeHead . "\r\n" . $body;
  }

  public function send() {
    $msg = $this->string();
    $cmd = ini_get("sendmail_path");
    $returnPath = $this->getReturnPath();
    if ($returnPath != "") {
      $cmd .= " -f" . escapeshellarg($returnPath);
    }
    $proc = proc_open($cmd, [["pipe", "r"]], $pipes);
    fwrite($pipes[0], $msg);
    fclose($pipes[0]);
    $r = proc_close($proc);
    if ($r) {
      throw new \Exception("err_send_mail");
    }
  }

  public function needCombine($name) {
    return in_array($name, [
      "Date", "Subject", "To", "Cc", "Bcc",
      "From", "Sender", "Reply-To", "Message-Id",
      "In-Reply-To", "References"
    ]);
  }

  public function combineHeader($fields) {
    $combined = [];
    while ($fields) {
      $name = $fields[0][0];
      if (!$this->needCombine($name)) {
        $combined[] = array_shift($fields);
        continue;
      }
      $values = [];
      foreach ($fields as $i => $field) {
        if ($field[0] == $name) {
          $values[] = $field[1];
          unset($fields[$i]);
        }
      }
      $combined[] = [$name, $values];
      $fields = array_values($fields);
    }
    return $combined;
  }

  public function encodeHeader($fields) {
    $s = "";
    foreach ($fields as $field) {
      $encoder = "encode" . str_replace("-", "", $field[0]);
      if (!method_exists($this, $encoder)) {
        $encoder = "encodeText";
      }
      $s .= $field[0] . ": " . $this->$encoder($field[1]) . "\r\n";
    }
    return $s;
  }

  public function encodeDate($value) {
    if (is_array($value)) {
      $value = $value[0];
    }
    return date("r", $value);
  }

  public function encodeTo($addresses) {
    return $this->encodeAddressList($addresses);
  }

  public function encodeCc($addresses) {
    return $this->encodeAddressList($addresses);
  }

  public function encodeFrom($addresses) {
    return $this->encodeAddressList($addresses);
  }

  public function encodeSender($addresses) {
    return $this->encodeAddress($addresses[0]);
  }

  public function encodeReplyTo($addresses) {
    return $this->encodeAddress($addresses[0]);
  }

  public function encodeAddress($value) {
    if (is_array($value)) {
      return sprintf(
        "%s <%s>", $this->encodeText($value[0]), $value[1]);
    }
    return $value;
  }

  public function encodeAddressList($values) {
    foreach ($values as $i => $value) {
      $values[$i] = $this->encodeAddress($value);
    }
    return join(",\r\n ", $values);
  }

  public function encodeMessageId($value) {
    if (is_array($value)) {
      $value = $value[0];
    }
    return "<$value>";
  }

  public function encodeInReplyTo($values) {
    return $this->encodeMessageIdList($values);
  }

  public function encodeReferences($values) {
    return $this->encodeMessageIdList($values);
  }

  public function encodeMessageIdList($values) {
    foreach ($values as $i => $value) {
      $values[$i] = $this->encodeMessageId($value);
    }
    return join(",\r\n ", $values);
  }

  public function encodeText($value) {
    if (is_array($value)) {
      $value = $value[0];
    }
    return mb_encode_mimeheader($value, "UTF-8", "B");
  }

  public function newMessageId() {
    $time = explode(" ", microtime());
    return sprintf(
      "%s.%s.%s@%s",
      $time[1],
      str_replace("0.", "", $time[0]), 
      mt_rand(), 
      php_uname("n"));
  }

  public function import($s) {
    $this->_fields = [];
    $s = preg_replace("/(\r\n|\r|\n)/u", "\n", $s);
    @list ($head, $body) = explode("\n\n", $s, 2);
    $head = preg_replace("/\n\\s+/u", " ", $head);
    $head = explode("\n", $head);
    foreach ($head as $field) {
      list ($name, $value) = explode(": ", $field, 2);
      $importer = "import" . str_replace("-", "", $name);
      if (method_exists($this, $importer)) {
        $this->$importer($value);
      }
      else {
        $this->addHeader($name, $value);
      }
    }
    $this->setBody($body);
  }

  public function importTo($value) {
    $this->importAddress("To", $value);
  }

  public function importCc($value) {
    $this->importAddress("Cc", $value);
  }

  public function importFrom($value) {
    $this->importAddress("From", $value);
  }

  public function importRreplyTo($value) {
    $this->importAddress("Reply-To", $value);
  }

  public function importAddress($name, $value) {
    $value = trim($value);
    if (preg_match("/(.*)<([^\\s]+?)>$/u", $value, $match)) {
      $display_name = trim($match[1]);
      $address = trim($match[2]);
      if ($display_name == "") {
        $value = $address;
      }
      else {
        $value = [$display_name, $address];
      }
    }
    $this->addHeader($name, $value);
  }
}
