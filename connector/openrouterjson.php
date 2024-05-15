<?php

$enginePath = dirname((__FILE__)) . DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."tokenizer_helper_functions.php");


class connector
{
    public $primary_handler;
    public $name;

    private $_functionName;
    private $_parameterBuff;
    private $_commandBuffer;
    private $_numOutputTokens;
    private $_dataSent;
    private $_fid;
    private $_buffer;
    private $_stopProc;
    public $_extractedbuffer;

    public function __construct()
    {
        $this->name="openrouterjson";
        $this->_commandBuffer=[];
        $this->_stopProc=false;
        $this->_extractedbuffer="";
        require_once(__DIR__."/__jpd.php");
    }


    public function open($contextData, $customParms)
    {
        $url = $GLOBALS["CONNECTOR"][$this->name]["url"];

        $MAX_TOKENS=((isset($GLOBALS["CONNECTOR"][$this->name]["max_tokens"]) ? $GLOBALS["CONNECTOR"][$this->name]["max_tokens"] : 48)+0);



        /***
            In the realm of perfection, the demand to tailor context for every language model would be nonexistent.

                                                                                                Tyler, 2023/11/09
        ****/
        
        if (isset($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"]) && $GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"] && isset($GLOBALS["MEMORY_STATEMENT"]) ) {
            foreach ($contextData as $n=>$contextline)  {
                if (strpos($contextline["content"],"#MEMORY")===0) {
                    $contextData[$n]["content"]=str_replace("#MEMORY","##\nMEMORY\n",$contextline["content"]."\n##\n");
                } else if (strpos($contextline["content"],$GLOBALS["MEMORY_STATEMENT"])!==false) {
                    $contextData[$n]["content"]=str_replace($GLOBALS["MEMORY_STATEMENT"],"(USE MEMORY reference)",$contextline["content"]);
                }
            }
        }
        
        $contextData[]= [
            'role' => 'user', 
            'content' => "Use this JSON object to give your answer: ".json_encode([
                "character"=>$GLOBALS["HERIKA_NAME"],
                "listener"=>"specify who {$GLOBALS["HERIKA_NAME"]} is talking to",
                "mood"=>'sarcastic|sassy|sardonic|irritated|mocking|playful|teasing|smug|amused|smirking|default',
                "action"=>'a valid action, (refer to available actions list) or \"None\"',
                "target"=>"action's target",
                "message"=>'message',
                
        ])
        ];
        
        
         if (isset($GLOBALS["FUNCTIONS_ARE_ENABLED"]) && $GLOBALS["FUNCTIONS_ARE_ENABLED"]) {
            foreach ($GLOBALS["FUNCTIONS"] as $function) {
                //$data["tools"][]=["type"=>"function","function"=>$function];
                $contextData[0]["content"].="\nAVAILABLE ACTION: {$function["name"]} ({$function["description"]})";
                if ($function["name"]==$GLOBALS["F_NAMES"]["Attack"]) {
                    $contextData[0]["content"].="(available targets: ".implode(",",$GLOBALS["FUNCTION_PARM_INSPECT"]).")";
                } else if ($function["name"]==$GLOBALS["F_NAMES"]["SetSpeed"]) {
                    $contextData[0]["content"].="(run|fastwalk|jog|walk)";
                }
            }
            $contextData[0]["content"].="\nAVAILABLE ACTION: Talk";
             

        }
        
        $pb=[];
        $pb["user"]="";
        foreach ($contextData as $n=>$element) {
            
            
            if ($n>=(sizeof($contextData)-2)) {
                // Last element
                $pb["user"].=$element["content"];
                
            } else {
                if ($element["role"]=="system") {
                    
                    $pb["system"]=$element["content"]."\nThis is the script history for this story\n#CONTEXT_HISTORY\n";
                    
                } else if ($element["role"]=="user") {
                    if (empty($element["content"])) {
                        unset($contextData[$n]);
                    }
                    
                    $pb["system"].=trim($element["content"])."\n";
                    
                } else if ($element["role"]=="assistant") {
                    
                    if (isset($element["role"]["tool_calls"]))
                        $pb["system"].="{$GLOBALS["HERIKA_NAME"]} issued ACTION {$element["tool_calls"]["function"]["name"]}";
                    else
                        $pb["system"].=$element["content"]."\n";
                    
                } else if ($element["role"]=="tool") {
                    
                        $pb["system"].=$element["content"]."\n";
                        
                }
            }
        }
        
        $contextData2=[];
        $contextData2[]= ["role"=>"system","content"=>$pb["system"]];
        $contextData2[]= ["role"=>"user","content"=>$pb["user"]];
        
        
        // Compacting */
        $contextDataCopy=[];
        foreach ($contextData as $n=>$element) 
            $contextDataCopy[]=$element;
        $contextData=$contextDataCopy;
        
        $data = array(
            'model' => (isset($GLOBALS["CONNECTOR"][$this->name]["model"])) ? $GLOBALS["CONNECTOR"][$this->name]["model"] : 'gpt-3.5-turbo-1106',
            'messages' =>
                $contextData
            ,
            'stream' => true,
            'max_tokens'=>$MAX_TOKENS,
            'temperature' => ($GLOBALS["CONNECTOR"][$this->name]["temperature"]) ?: 1,
            'top_p' => ($GLOBALS["CONNECTOR"][$this->name]["top_p"]) ?: 1,
            //'response_format'=>["type"=>"json_object"],
            
        );
        // Mistral AI API does not support penalty params
        if (strpos($url, "mistral") === false) {
            $data["presence_penalty"]=($GLOBALS["CONNECTOR"][$this->name]["presence_penalty"]) ?: 0;
            $data["frequency_penalty"]=($GLOBALS["CONNECTOR"][$this->name]["frequency_penalty"]) ?: 0;
        }
  
        

        if (isset($customParms["MAX_TOKENS"])) {
            if ($customParms["MAX_TOKENS"]==0) {
                unset($data["max_tokens"]);
            } elseif (isset($customParms["MAX_TOKENS"])) {
                $data["max_tokens"]=$customParms["MAX_TOKENS"]+0;
            }
        }

        if (isset($GLOBALS["FORCE_MAX_TOKENS"])) {
            if ($GLOBALS["FORCE_MAX_TOKENS"]==0) {
                unset($data["max_tokens"]);
            } else
                $data["max_tokens"]=$GLOBALS["FORCE_MAX_TOKENS"]+0;
            
        }

       


        $GLOBALS["DEBUG_DATA"]["full"]=($data);

        file_put_contents(__DIR__."/../log/context_sent_to_llm.log",date(DATE_ATOM)."\n=\n".print_r($data,true)."=\n", FILE_APPEND);

        $headers = array(
            'Content-Type: application/json',
            "Authorization: Bearer {$GLOBALS["CONNECTOR"][$this->name]["API_KEY"]}",
            "HTTP-Referer:  {$GLOBALS["CONNECTOR"][$this->name]["xreferer"]}",
            "X-Title: {$GLOBALS["CONNECTOR"][$this->name]["xtitle"]}"
        );

        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($data),
                'timeout' => ($GLOBALS["HTTP_TIMEOUT"]) ?: 30
            )
        );

        $context = stream_context_create($options);
        
        $this->primary_handler = fopen($url, 'r', false, $context);
        if (!$this->primary_handler) {
                error_log(print_r(error_get_last(),true));
                return null;
        }

        $this->_dataSent=json_encode($data);    // Will use this data in tokenizer.

        
        return true;


    }


    public function process()
    {
        global $alreadysent;

        static $numOutputTokens=0;

        $line = fgets($this->primary_handler);
        $buffer="";
        $totalBuffer="";
        $finalData="";
        $mangledBuffer="";
        
        file_put_contents(__DIR__."/../log/debugStream.log", $line, FILE_APPEND);

        $data=json_decode(substr($line, 6), true);
        if (isset($data["choices"][0]["delta"]["content"])) {
            if (strlen(($data["choices"][0]["delta"]["content"]))>0) {
                $buffer.=$data["choices"][0]["delta"]["content"];
                $this->_buffer.=$data["choices"][0]["delta"]["content"];
                $this->_numOutputTokens += 1;

            }
            $totalBuffer.=$data["choices"][0]["delta"]["content"];

        }
        
        $buffer="";
        if (!empty($this->_buffer))
            $finalData=__jpd_decode_lazy($this->_buffer, true);
            if (is_array($finalData)) {
                
                
                if (isset($finalData[0])&& is_array($finalData[0]))
                    $finalData=$finalData[0];
                
                
                if (isset($finalData["message"])) {
                    if (is_array($finalData)&&isset($finalData["message"])) {
                        $mangledBuffer = str_replace($this->_extractedbuffer, "", $finalData["message"]);
                        $this->_extractedbuffer=$finalData["message"];
                        if (isset($finalData["listener"])) {
                            $GLOBALS["SCRIPTLINE_LISTENER"]=$finalData["listener"];
                        }
                        
                        if (isset($finalData["mood"])) {
                            $GLOBALS["SCRIPTLINE_ANIMATION"]=GetAnimationHex($finalData["mood"]);
                        }
                        
                    }
                }
                
            } else
                $buffer="";
        
        return $mangledBuffer;
    }

    // Method to close the data processing operation
    public function close()
    {

        fclose($this->primary_handler);
        if ($GLOBALS["FEATURES"]["COST_MONITOR"]["ENABLED"]) {
            // Call rest of tokenizer functions now, relevant data was sent

            TkTokenizePrompt($this->_dataSent, $GLOBALS["CONNECTOR"][$this->name]["model"]);
            TkTokenizeResponse($this->_numOutputTokens, $GLOBALS["CONNECTOR"][$this->name]["model"]);
        }
        
        //file_put_contents(__DIR__."/../log/ouput_from_llm.log",$this->_buffer, FILE_APPEND | LOCK_EX);
        file_put_contents(__DIR__."/../log/output_from_llm.log",date(DATE_ATOM)."\n=\n".$this->_buffer."\n=\n", FILE_APPEND);


    }

    // Method to close the data processing operation
    public function processActions()
    {
        global $alreadysent;

        if ($this->_functionName) {
            $parameterArr = json_decode($this->_parameterBuff, true);
            if (is_array($parameterArr)) {
                $parameter = current($parameterArr); // Only support for one parameter

                if (!isset($alreadysent[md5("{$GLOBALS["HERIKA_NAME"]}|command|{$this->_functionName}@$parameter\r\n")])) {
                    $functionCodeName=getFunctionCodeName($this->_functionName);
                    $this->_commandBuffer[]="{$GLOBALS["HERIKA_NAME"]}|command|$functionCodeName@$parameter\r\n";
                    //echo "Herika|command|$functionCodeName@$parameter\r\n";

                }

                $alreadysent[md5("{$GLOBALS["HERIKA_NAME"]}|command|{$this->_functionName}@$parameter\r\n")] = "{$GLOBALS["HERIKA_NAME"]}|command|{$this->_functionName}@$parameter\r\n";
                @ob_flush();
            } else 
                return null;
        } else {
            $GLOBALS["DEBUG_DATA"]["RAW"]=$this->_buffer;
            $parsedResponse=__jpd_decode_lazy($this->_buffer);   // USE JPD_LAZY?
            if (is_array($parsedResponse)) {
                if (!empty($parsedResponse["action"])) {
                    if (!isset($alreadysent[md5("{$GLOBALS["HERIKA_NAME"]}|command|{$parsedResponse["action"]}@{$parsedResponse["target"]}\r\n")])) {
                        
                        $functionDef=findFunctionByName(trim($parsedResponse["action"]));
                        if ($functionDef) {
                            $functionCodeName=getFunctionCodeName($parsedResponse["action"]);
                            if (@strlen($functionDef["parameters"]["required"][0])>0) {
                                if (!empty($parsedResponse["target"])) {
                                    $this->_commandBuffer[]="{$GLOBALS["HERIKA_NAME"]}|command|$functionCodeName@{$parsedResponse["target"]}\r\n";
                                }
                                else {
                                    error_log("Missing required parameter");
                                }
                                    
                            } else {
                                $this->_commandBuffer[]="{$GLOBALS["HERIKA_NAME"]}|command|$functionCodeName@{$parsedResponse["target"]}\r\n";
                            }
                        } else {
                            error_log("Function not found for {$parsedResponse["action"]}");
                        }
                        
                        //$functionCodeName=getFunctionCodeName($parsedResponse["action"]);
                        //$this->_commandBuffer[]="{$GLOBALS["HERIKA_NAME"]}|command|{$parsedResponse["action"]}@{$parsedResponse["target"]}\r\n";
                        //echo "Herika|command|$functionCodeName@$parameter\r\n";
                        $alreadysent[md5("{$GLOBALS["HERIKA_NAME"]}|command|{$parsedResponse["action"]}@{$parsedResponse["target"]}\r\n")]=end($this->_commandBuffer);
                    
                    } 
                        
                }
                
                @ob_flush();    
            } else {
                error_log("No actions");
                return [];
            }
        }

        return $this->_commandBuffer;
    }

    public function isDone()
    {
        return feof($this->primary_handler);
    }

}
