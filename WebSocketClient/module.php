<?

require_once(__DIR__ . "/../WebsocketClass.php");  // diverse Klassen

use PTLS\TLSContext;
use PTLS\Exceptions\TLSAlertException;

/*
 * @addtogroup websocket
 * @{
 *
 * @package       Websocket
 * @file          module.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2017 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.2
 *
 */

/**
 * WebsocketClient Klasse implementiert das Websocket Protokoll als HTTP-Client
 * Erweitert IPSModule.
 * 
 * @package       Websocket
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2017 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.2
 * @example <b>Ohne</b>
 * @property WebSocketState $State
 * @property string $Buffer
 * @property string $Handshake
 * @property string $Key
 * @property int $Parent
 * @property WebSocketOPCode $PayloadTyp
 * @property string  $PayloadReceiveBuffer
 * @property string  $PayloadSendBuffer
 * @property bool  $WaitForPong
 * @property TLS $TLS TLS-Object
 * @property string $TLSBuffer
 * @property array $TLSBuffers
 * @property bool $UseTLS
 */
class WebsocketClient extends IPSModule
{

    use DebugHelper,
        InstanceStatus;

    /**
     * Wert einer Eigenschaft aus den InstanceBuffer lesen.
     * 
     * @access public
     * @param string $name Propertyname
     * @return mixed Value of Name
     */
    public function __get($name)
    {
        if ($name == "TLS")
        {
            $Lines = "";
            foreach ($this->TLSBuffers as $Buffer)
            {
                $Lines .= $this->{$name . 'Part' . $Buffer};
            }
            return unserialize($Lines);
        }

        return unserialize($this->GetBuffer($name));
    }

    /**
     * Wert einer Eigenschaft in den InstanceBuffer schreiben.
     * 
     * @access public
     * @param string $name Propertyname
     * @param mixed Value of Name
     */
    public function __set($name, $value)
    {
//        $this->SetBuffer($name, serialize($value));
        $Data = serialize($value);
        if ($name == "TLS")
        {
//            $this->SendDebug("TLS SIZE", strlen($Data), 0);
            $OldBuffers = $this->TLSBuffers;
//            $this->SendDebug('TLSBuffers old', count($OldBuffers), 0);
            $Lines = str_split($Data, 8000);
            foreach ($Lines as $BufferIndex => $BufferLine)
            {
                $this->{$name . 'Part' . $BufferIndex} = $BufferLine;
            }
            $NewBuffers = array_keys($Lines);
            $this->TLSBuffers = $NewBuffers;
//            $this->SendDebug('TLSBuffers new', count($NewBuffers), 0);
            $DelBuffers = array_diff_key($OldBuffers, $NewBuffers);
//            $this->SendDebug('TLSBuffers del', count($DelBuffers), 0);
            foreach ($DelBuffers as $DelBuffer)
            {
                $this->{$name . 'Part' . $DelBuffer} = "";
//                $this->SendDebug('TLSBuffers' . $DelBuffer, 'DELETE', 0);
            }
            return;
        }
//        elseif ($name == "State")
//        {
//            $this->SendDebug('STATE', WebSocketState::ToString($value), 0);
//        }
        $this->SetBuffer($name, $Data);
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function Create()
    {
        parent::Create();
        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");
        $this->RegisterPropertyString("URL", "");
        $this->RegisterPropertyBoolean("Open", false);
        $this->RegisterPropertyInteger("Frame", WebSocketOPCode::text);
        $this->RegisterPropertyBoolean("BasisAuth", false);
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyBoolean("TLS", false);
        $this->Buffer = '';
        $this->State = WebSocketState::unknow;
        $this->WaitForPong = false;
        $this->TLSBuffers = array();
        $this->UseTLS = false;
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message)
        {
            case IPS_KERNELSTARTED:
                try
                {
                    $this->KernelReady();
                }
                catch (Exception $exc)
                {
                    return;
                }
                break;
            case DM_DISCONNECT:
                $this->GetParentData();
                $this->State = WebSocketState::unknow; // zum abmelden ist es schon zu spät, da Verbindung weg ist.
                break;
            case DM_CONNECT:
                $this->ForceRefresh();
                break;
            case IM_CHANGESTATUS:
                if ($SenderID == $this->Parent)
                {
                    if ($Data[0] == IS_ACTIVE)
                    {
                        $this->ForceRefresh();
                    }
                    else
                    {
                        $this->State = WebSocketState::unknow; // zum abmelden ist es schon zu spät, da Verbindung weg ist.
                    }
                }
                break;
        }
    }

    /**
     * Wird ausgeführt wenn der Kernel hochgefahren wurde.
     */
    protected function KernelReady()
    {
        if ($this->State == WebSocketState::unknow)
            $this->ApplyChanges();
    }

    /**
     * Wird ausgeführt wenn sich der Parent ändert.
     */
    protected function ForceRefresh()
    {
        if ($this->State == WebSocketState::unknow)
            if ($this->ReadPropertyBoolean("Open"))
                $this->ApplyChanges();
    }

    /**
     * Interne Funktion des SDK.
     * 
     * @access public
     */
    public function ApplyChanges()
    {
        if (($this->State != WebSocketState::unknow) and ( $this->State != WebSocketState::Connected))
            return;
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, DM_CONNECT);
        $this->RegisterMessage($this->InstanceID, DM_DISCONNECT);
        // Wenn Kernel nicht bereit, dann warten... KR_READY kommt ja gleich

        if (IPS_GetKernelRunlevel() <> KR_READY)
            return;

        $OldState = $this->State;
        $this->State = WebSocketState::init;
        //Verbindung beenden ?
        if ($OldState == WebSocketState::Connected)
            $this->SendDisconnect();

        parent::ApplyChanges();
        $this->Buffer = '';
        $this->TLSBuffer = '';

        $Open = $this->ReadPropertyBoolean('Open');
        $NewState = IS_ACTIVE;
        if (!$Open)
            $NewState = IS_INACTIVE;
        else
        {
            if (!in_array((string) parse_url($this->ReadPropertyString('URL'), PHP_URL_SCHEME), array('http', 'https', 'ws', 'wss')))
            {
                $NewState = IS_EBASE + 2;
                $Open = false;
                trigger_error('Invalid URL', E_USER_NOTICE);
            }
        }
        $ParentID = $this->GetParentData();


        // Zwangskonfiguration des ClientSocket
        if ($ParentID > 0)
        {

            if (IPS_GetProperty($ParentID, 'Host') <> (string) parse_url($this->ReadPropertyString('URL'), PHP_URL_HOST))
                IPS_SetProperty($ParentID, 'Host', (string) parse_url($this->ReadPropertyString('URL'), PHP_URL_HOST));
            switch ((string) parse_url($this->ReadPropertyString('URL'), PHP_URL_SCHEME))
            {
                case 'https':
                case 'wss':
                    $Port = 443;
                    $this->UseTLS = true;
                    break;
                default:
                    $Port = 80;
                    $this->UseTLS = false;
            }
            $OtherPort = (int) parse_url($this->ReadPropertyString('URL'), PHP_URL_PORT);
            if ($OtherPort != 0)
                $Port = $OtherPort;
            if (IPS_GetProperty($ParentID, 'Port') <> $Port)
                IPS_SetProperty($ParentID, 'Port', $Port);
            if (IPS_GetProperty($ParentID, 'Open') <> $Open)
                IPS_SetProperty($ParentID, 'Open', $Open);
            @IPS_ApplyChanges($ParentID);
        }
        else
        {
            if ($Open)
            {
                $NewState = IS_INACTIVE;
                $Open = false;
            }
        }

        if ($Open)
        {
            if ($this->HasActiveParent($ParentID))
            {
//                if ($this->ReadPropertyBoolean('TLS'))
                if ($this->UseTLS)
                {
                    if (!$this->CreateTLSConnection())
                    {
                        $this->SetStatus(IS_EBASE + 3);
                        $this->State = WebSocketState::unknow;
                        return;
                    }
                }

                $ret = $this->InitHandshake();
                //$ret = true;
                if ($ret !== true)
                {
                    $NewState = IS_EBASE + 3;
                }
            }
            else
            {
                $NewState = IS_EBASE + 1;
                trigger_error('Could not connect.', E_USER_NOTICE);
            }
        }

        if ($NewState != IS_ACTIVE)
            $this->State = WebSocketState::unknow;

        $this->SetStatus($NewState);
    }

################## PRIVATE     

    private function CreateTLSConnection()
    {
        //$SendData = $this->InitTLS();
        $TLSconfig = TLSContext::getClientConfig([]);
        // Create a TLS Engine
        $TLS = TLSContext::createTLS($TLSconfig);
        $this->SendDebug('TLS start', '', 0);
        $loop = 1;
        $SendData = $TLS->decode();
        $this->SendDebug('Send TLS Handshake ' . $loop, $SendData, 0);
        $this->State = WebSocketState::TLSisSend;
        $JSON['DataID'] = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
        $JSON['Buffer'] = utf8_encode($SendData);
        $JsonString = json_encode($JSON);
        parent::SendDataToParent($JsonString);
        while (!$TLS->isHandshaked() && ($loop < 10))
        {
            $loop++;
            $Result = $this->WaitForResponse(WebSocketState::TLSisReceived);
            if ($Result === false)
            {
                $this->SendDebug('TLS no answer', '', 0);
                trigger_error('TLS no answer', E_USER_NOTICE);
                break;
            }
            $this->State = WebSocketState::TLSisSend;

            $this->SendDebug('Get TLS Handshake', $Result, 0);
            try
            {
                // Calling encode method to 
                $TLS->encode($Result);
                if ($TLS->isHandshaked())
                    break;
            }
            catch (TLSAlertException $e)
            {
                trigger_error($e->getMessage(), E_USER_NOTICE);
//            if (strlen($out = $e->decode()))
//                stream_socket_sendto($socket, $out);
                return false;
            }

//        $this->SendDebug('TLS Session', $TLS->getDebug()->getSessionID(), 0);
//        $this->SendDebug('TLS Protocol', $TLS->getDebug()->getProtocolVersion(), 0);
//            $this->SendDebug('TLS isHandshaked', ($TLS->isHandshaked() ? "true" : "false"), 0);
//            $this->SendDebug('TLS isClosed', ($TLS->isClosed() ? "true" : "false"), 0);
            $SendData = $TLS->decode();
            if (strlen($SendData) > 0)
            {
                $this->SendDebug('TLS loop ' . $loop, $SendData, 0);
                $JSON['DataID'] = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
                $JSON['Buffer'] = utf8_encode($SendData);
                $JsonString = json_encode($JSON);
                parent::SendDataToParent($JsonString);
            }
            else
            {
                $this->SendDebug('TLS waiting loop ' . $loop, $SendData, 0);
            }
        }
        if (!$TLS->isHandshaked())
            return false;
        $this->TLS = $TLS;
        $this->State = WebSocketState::init;
        $this->SendDebug('TLS ProtocolVersion', $TLS->getDebug()->getProtocolVersion(), 0);
        $UsingCipherSuite = explode("\n", $TLS->getDebug()->getUsingCipherSuite());
        unset($UsingCipherSuite[0]);
        foreach ($UsingCipherSuite as $Line)
        {
            $this->SendDebug(trim(substr($Line, 0, 14)), trim(substr($Line, 15)), 0);
        }
        return true;
    }

    private function InitHandshake()
    {
        if ($this->State <> WebSocketState::init)
            return false;

        $URL = parse_url($this->ReadPropertyString('URL'));
        if (!isset($URL['path']))
            $URL['path'] = "/";

        $SendKey = base64_encode(openssl_random_pseudo_bytes(12));
        $Key = base64_encode(sha1($SendKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
        //senden
        $Header[] = 'GET ' . $URL['path'] . ' HTTP/1.1';
        $Header[] = 'Host: ' . $URL['host'];
        if ($this->ReadPropertyBoolean("BasisAuth"))
        {
            $realm = base64_encode($this->ReadPropertyString("Username") . ':' . $this->ReadPropertyString("Password"));
            $Header[] = 'Authorization: Basic ' . $realm;
        }
        $Header[] = 'Upgrade: websocket';
        $Header[] = 'Connection: Upgrade';
        $Header[] = 'Sec-WebSocket-Key: ' . $SendKey;
        //$Header[] = ' Origin: http://' . $URL['host'];
        //$Header[] = 'Sec-WebSocket-Protocol: chat';
        //Authorization
        $Header[] = 'Sec-WebSocket-Version: 13';
        $Header[] = "\r\n";
        $SendData = implode("\r\n", $Header);
        $this->SendDebug('Send Handshake', $SendData, 0);
        $this->State = WebSocketState::HandshakeSend;
        try
        {
//            if ($this->ReadPropertyBoolean('TLS'))
            if ($this->UseTLS)
            {
//                $TLSconfig = TLSContext::getClientConfig([]);
                // Create a TLS Engine
//                $TLS = TLSContext::createTLS($config);
                $TLS = $this->TLS;
                $SendData = $TLS->output($SendData)->decode();
                $this->TLS = $TLS;
                $this->SendDebug('Send TLS', $SendData, 0);
            }
            $JSON['DataID'] = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
            $JSON['Buffer'] = utf8_encode($SendData);
            $JsonString = json_encode($JSON);
            parent::SendDataToParent($JsonString);
            // Antwort lesen
            $Result = $this->WaitForResponse(WebSocketState::HandshakeReceived);
            if ($Result === false)
                throw new Exception('no answer');

            $this->SendDebug('Get Handshake', $Result, 0);

            if (preg_match("/HTTP\/1.1 (\d{3}) /", $Result, $match))
                if ((int) $match[1] <> 101)
                    throw new Exception('Handshake error');

            if (preg_match("/Connection: (.*)\r\n/", $Result, $match))
                if (strtolower($match[1]) != 'upgrade')
                    throw new Exception('Handshake error');

            if (preg_match("/Upgrade: (.*)\r\n/", $Result, $match))
                if (strtolower($match[1]) != 'websocket')
                    throw new Exception('Handshake error');

            if (preg_match("/Sec-WebSocket-Accept: (.*)\r\n/", $Result, $match))
                if ($match[1] <> $Key)
                    throw new Exception('Sec-WebSocket not match');
        }
        catch (Exception $exc)
        {
            $this->State = WebSocketState::unknow;
            trigger_error($exc->getMessage(), E_USER_NOTICE);
            return false;
        }
        $this->State = WebSocketState::Connected;
        return true;
    }

    /**
     * Dekodiert die empfangenen Daten und sendet sie an die Childs.
     * 
     * @access private
     * @param WebSocketFrame $Frame Ein Objekt welches einen kompletten Frame enthält.
     */
    private function DecodeFrame(WebSocketFrame $Frame)
    {
        $this->SendDebug('Receive', $Frame, ($Frame->OpCode == WebSocketOPCode::continuation) ? $this->PayloadTyp - 1 : $Frame->OpCode - 1);

        switch ($Frame->OpCode)
        {
            case WebSocketOPCode::ping:
                $this->SendPong($Frame->Payload);
                return;
            case WebSocketOPCode::close:
                $this->SendDisconnect();
                $this->State = WebSocketState::unknow;
                return;
            case WebSocketOPCode::text:
            case WebSocketOPCode::binary:
                $this->PayloadTyp = $Frame->OpCode;
                $Data = $Frame->Payload;
                break;
            case WebSocketOPCode::continuation:
                $Data = $this->PayloadReceiveBuffer . $Frame->Payload;
                break;
            case WebSocketOPCode::pong:
                $this->Handshake = $Frame->Payload;
                $this->WaitForPong = true;
                return;
        }

        if ($Frame->Fin)
        {
            $this->SendDataToChilds($Data); // RAW Childs
        }
        else
        {
            $this->PayloadReceiveBuffer = $Data;
        }
    }

    private function SendPong(string $Payload = null)
    {
        $this->Send($Payload, WebSocketOPCode::pong);
    }

    private function SendDisconnect()
    {
        $this->Send("", WebSocketOPCode::close);
    }

    /**
     * Versendet RawData mit OpCode an den IO.
     * 
     * @access protected
     * @param string $RawData 
     * @param WebSocketOPCode $OPCode
     */
    protected function Send(string $RawData, int $OPCode, $Fin = true)
    {

        $WSFrame = new WebSocketFrame($OPCode, $RawData);
        $WSFrame->Fin = $Fin;
        $Frame = $WSFrame->ToFrame(true);
        $this->SendDebug('Send', $WSFrame, 0);
        $this->SendDataToParent($Frame);
    }

################## DATAPOINTS CHILDS

    /**
     * Interne Funktion des SDK. Nimmt Daten von Childs entgegen und sendet Diese weiter.
     * 
     * @access public
     * @param string $JSONString
     * @result bool true wenn Daten gesendet werden konnten, sonst false.
     */
    public function ForwardData($JSONString)
    {
        if ($this->State <> WebSocketState::Connected)
        {
            trigger_error("Not connected", E_USER_NOTICE);
            return false;
        }
        $Data = json_decode($JSONString);
        if ($Data->DataID == "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}") //Raw weitersenden
        {
            $this->SendText(utf8_decode($Data->Buffer));
        }
        /* Funktioniert nicht :(
          if ($Data->DataID == "{4A550680-80C5-4465-971E-BBF83205A02B}") // HID für ReportID
          {
          $this->Send(utf8_decode($Data->Buffer), $Data->EventID+1);
          }
         */
    }

    /**
     * Sendet die Rohdaten an die Childs.
     * 
     * @access private
     * @param string $RawData
     */
    private function SendDataToChilds(string $RawData)
    {
        $JSON['DataID'] = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';
        $JSON['Buffer'] = utf8_encode($RawData);
        $Data = json_encode($JSON);
        $this->SendDataToChildren($Data);

        /*  Funktioniert nicht :(
          $JSON['DataID'] = '{FD7FF32C-331E-4F6B-8BA8-F73982EF5AA7}';
          $JSON['Buffer'] = utf8_encode($RawData);
          $JSON['EventID'] = $this->PayloadTyp-1;
          $Data = json_encode($JSON);
          $this->SendDataToChildren($Data);
         */
    }

################## DATAPOINTS PARENT    

    /**
     * Empfängt Daten vom Parent.
     * 
     * @access public
     * @param string $JSONString Das empfangene JSON-kodierte Objekt vom Parent.
     * @result bool True wenn Daten verarbeitet wurden, sonst false.
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if ($this->UseTLS)
        {
            $Data = $this->TLSBuffer . utf8_decode($data->Buffer);
//echo dechex(ord($Data[0])& 0xFC).PHP_EOL;

            if (((ord($Data[0]) & 0xFC) == 0x14) && (substr($Data, 1, 2) == "\x03\x03"))
            {
                $len = unpack("n", substr($Data, 3, 2))[1] + 5;
                if (strlen($Data) >= $len)
                {
                    if (($this->State == WebSocketState::TLSisSend) or ( $this->State == WebSocketState::TLSisReceived))
                    {
                        $this->WaitForResponse(WebSocketState::TLSisSend);
                        $this->TLSBuffer = "";
                        $this->SendDebug('Receive TLS Frame', $Data, 0);
                        $this->Handshake = $Data;
                        $this->State = WebSocketState::TLSisReceived;


                        return;
                    }
                    else
                    {
                        $this->TLSBuffer = "";
                        $this->SendDebug('Receive TLS Frame', $Data, 0);
                        $TLS = $this->TLS;
                        $TLS->encode($Data);
                        $Data = $TLS->input();
                        $this->TLS = $TLS;
                    }
                }
                else
                {
                    $this->TLSBuffer = $Data;
                    $this->SendDebug('Receive TLS Part', utf8_decode($data->Buffer), 0);
                    return;
                }
            }
            else // Anfang (inkl. Buffer) paßt nicht
            {
                $this->TLSBuffer = "";
                return;
            }
        }
        else
        {
            $Data = utf8_decode($data->Buffer);
        }

        $Data = $this->Buffer . $Data;
        switch ($this->State)
        {
            case WebSocketState::HandshakeSend:
                if (strpos($Data, "\r\n\r\n") !== false)
                {
                    $this->Handshake = $Data;
                    $this->State = WebSocketState::HandshakeReceived;
                    $Data = "";
                }
                else
                {
                    $this->SendDebug('Receive inclomplete Handshake', $Data, 0);
                }
                $this->Buffer = $Data;
                break;
            case WebSocketState::Connected:
                $this->SendDebug('ReceivePacket', $Data, 1);
                $Frame = new WebSocketFrame($Data);
                $Data = $Frame->Tail;
                $Frame->Tail = null;
                $this->Buffer = $Data;
                $this->DecodeFrame($Frame);
                break;
        }
    }

    /**
     * Sendet ein Paket an den Parent.
     * 
     * @access protected
     * @param string $Data
     */
    protected function SendDataToParent($Data)
    {
        $JSON['DataID'] = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
        //if ($this->ReadPropertyBoolean('TLS'))
        if ($this->UseTLS)
        {
            $TLS = $this->TLS;
            $this->SendDebug('Send TLS', $Data, 0);
            $Data = $TLS->output($Data)->decode();
            $this->TLS = $TLS;
        }
        $JSON['Buffer'] = utf8_encode($Data);
        $JsonString = json_encode($JSON);
        $this->SendDebug('Send Packet', $Data, 1);
        parent::SendDataToParent($JsonString);
    }

################## DATAPOINTS PUBLIC

    /**
     * Versendet RawData mit OpCode an den IO.
     * 
     * @access public
     * @param string $Text 
     */
    public function SendText(string $Text)
    {
        $this->Send($Text, $this->ReadPropertyInteger('Frame'));
        /*
          $WSFrame = new WebSocketFrame($this->ReadPropertyInteger('Frame'), $Text);
          $WSFrame->Fin = true;
          $Frame = $WSFrame->ToFrame(true);
          $this->SendDebug('Send', $WSFrame, 0);
          $this->SendDataToParent($Frame); */
    }

    /**
     * Versendet ein String
     * 
     * @access public
     * @param bool $Fin
     * @param int $OPCode
     * @param string $Text
     */
    public function SendPacket(bool $Fin, int $OPCode, string $Text)
    {
        $this->Send($Text, $OPCode, $Fin);
        /*
          $WSFrame = new WebSocketFrame($OPCode, $Text);
          $WSFrame->Fin = $Fin;
          $Frame = $WSFrame->ToFrame(true);
          $this->SendDebug('Send', $WSFrame, 0);
          $this->SendDataToParent($Frame); */
    }

    /**
     * Versendet ein String
     * 
     * @access public
     * @param string $Text
     */
    public function SendPing(string $Text)
    {
        $this->Send($Text, WebSocketOPCode::ping);
        /* $WSFrame = new WebSocketFrame(WebSocketOPCode::ping, $Text);
          $WSFrame->Fin = $Fin;
          $Frame = $WSFrame->ToFrame(true);
          $this->SendDebug('Send', $WSFrame, 0);
          $this->SendDataToParent($Frame); */
        $Result = $this->WaitForPong();
        $this->Handshake = "";
        if ($Result === false)
        {
            trigger_error('Timeout', E_USER_NOTICE);
            return false;
        }

        if ($Result != $Text)
        {
            trigger_error('Wrong pong received', E_USER_NOTICE);
            return false;
        }
        return true;
    }

    /**
     * Wartet auf eine Handshake-Antwort.
     * 
     * @access private
     */
    private function WaitForResponse(int $State)
    {
        for ($i = 0; $i < 500; $i++)
        {
            if ($this->State == $State)
            {
                $Handshake = $this->Handshake;
                $this->Handshake = "";
                return $Handshake;
            }
            IPS_Sleep(5);
        }
        return false;
    }

    /**
     * Wartet auf eine Handshake-Antwort.
     * 
     * @access private
     */
    private function WaitForPong()
    {
        for ($i = 0; $i < 1000; $i++)
        {
            if ($this->WaitForPong === true)
            {
                $Handshake = $this->Handshake;
                $this->Handshake = "";
                return $Handshake;
            }
            IPS_Sleep(5);
        }
        return false;
    }

################## SENDQUEUE

    /**
     * Erzeugt einen neuen Parent, wenn keiner vorhanden ist.
     * 
     * @param string $ModuleID Die GUID des benötigten Parent.
     */
    protected function RequireParent($ModuleID)
    {
        $instance = IPS_GetInstance($this->InstanceID);
        if ($instance['ConnectionID'] == 0)
        {
            $parentID = IPS_CreateInstance($ModuleID);
            $instance = IPS_GetInstance($parentID);
            IPS_SetName($parentID, "WebsocketClient Socket");
            IPS_ConnectInstance($this->InstanceID, $parentID);
        }
    }

}

/** @} */