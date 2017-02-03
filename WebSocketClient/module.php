<?

require_once(__DIR__ . "/../WebsocketClass.php");  // diverse Klassen

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
        $this->SetBuffer($name, serialize($value));
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
        $this->Buffer = '';
        $this->State = WebSocketState::unknow;
        $this->WaitForPong = false;
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
            $Port = (int) parse_url($this->ReadPropertyString('URL'), PHP_URL_PORT);
            if ($Port == 0)
            {
                switch ((string) parse_url($this->ReadPropertyString('URL'), PHP_URL_SCHEME))
                {
                    case 'https':
                    case 'wss':
                        $Port = 443;
                    default:
                        $Port = 80;
                }
            }
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
            $JSON['DataID'] = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
            $JSON['Buffer'] = utf8_encode($SendData);
            $JsonString = json_encode($JSON);
            parent::SendDataToParent($JsonString);
            // Antwort lesen
            $Result = $this->WaitForResponse();
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
    protected function Send(string $RawData, int $OPCode)
    {

        $WSFrame = new WebSocketFrame($OPCode, $RawData);
        $WSFrame->Fin = true;
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
        // Datenstream zusammenfügen
        $head = $this->Buffer;
        $tail = '';
        $Data = $head . utf8_decode($data->Buffer);
        switch ($this->State)
        {
            case WebSocketState::HandshakeSend:
                if (substr($Data, -4) == "\r\n\r\n")
                {
                    $this->Handshake = $Data;
                    $this->State = WebSocketState::HandshakeReceived;
                    $Data = "";
                }
                break;
            case WebSocketState::Connected:
                $this->SendDebug('ReceivePacket', $Data, 1);
                $Frame = new WebSocketFrame($Data);
                $Data = $Frame->Tail;
                $Frame->Tail = null;
                $this->DecodeFrame($Frame);
                break;
            case WebSocketState::unknow:
                $Data = "";
                break;
        }
        $this->Buffer = $Data;
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

        $WSFrame = new WebSocketFrame($this->ReadPropertyInteger('Frame'), $Text);
        $WSFrame->Fin = true;
        $Frame = $WSFrame->ToFrame(true);
        $this->SendDebug('Send', $WSFrame, 0);
        $this->SendDataToParent($Frame);
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
        $WSFrame = new WebSocketFrame($OPCode, $Text);
        $WSFrame->Fin = $Fin;
        $Frame = $WSFrame->ToFrame(true);
        $this->SendDebug('Send', $WSFrame, 0);
        $this->SendDataToParent($Frame);
    }

    /**
     * Versendet ein String
     * 
     * @access public
     * @param string $Text
     */
    public function SendPing(string $Text)
    {
        $WSFrame = new WebSocketFrame(WebSocketOPCode::ping, $Text);
        $WSFrame->Fin = $Fin;
        $Frame = $WSFrame->ToFrame(true);
        $this->SendDebug('Send', $WSFrame, 0);
        $this->SendDataToParent($Frame);
        $Result = $this->WaitForPong();
        $this->Handshake = "";
        if ($Result === false)
        {
            trigger_error('Timeout', E_USER_NOTICE);
            return false;
        }

        if ($Result !== $Text)
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
    private function WaitForResponse()
    {
        for ($i = 0; $i < 1000; $i++)
        {
            if ($this->State == WebSocketState::HandshakeReceived)
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