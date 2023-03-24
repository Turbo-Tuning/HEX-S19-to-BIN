<?php

class IntelHEXandS19{

    var $fh;
    //var $binutils = false;

    public function __construct(){

    }

    public function Open($filename){
        $start_time = microtime(true);
        //if($this->binutils) return $this->objCopy($filename);
        $originaldata = '';
        $lastaddress = 0;
        $addressbase = 0;
        $dumpfilesize = filesize($filename);
        if($dumpfilesize == 0){
            return '';
        }
        $this->fh = fopen($filename, 'rb');
        
        $line = fgets($this->fh);

        
        Msg('Processing '.$filename);
        
        if(substr($line, 0, 1) == ':'){
            //Reading in Intel HEX format
            Msg ('Reading dump of '.round($dumpfilesize/1024/1024, 4).'mB in Intel hex format');
            fseek($this->fh, 0);
            while (($line = fgets($this->fh)) !== false) {
                // process the line read.
                $linef = rtrim($line);
                $lineff = substr($linef, 1);
                if(!ctype_xdigit($lineff)){
                    Msg('Not IntelHEX');
                    return '';
                }
                //$bytes = pack("H*" , $lineff);
                //$dumpline = unpack('C*',$bytes );
                $dumpline = str_split($lineff, 2);
                //$dumpline = rekeyarray($dumpline);*/
                # :0200000480007A
                # :BBAAAATTHHHHCC
                # :20034000203009F8560809F65708069189F314088F18209089F5150802258F1620A009FFDB
                # :BBAAAATTHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHCC
                # : = startcode, BB = bytecount, AAAA = address, TT = recordtype, HHHH = data, CC = checksum
                $bytecount  = hexdec($dumpline[0]);
                
                # Check data and line length validity
                if (sizeof($dumpline) == (($bytecount) + 5)){
                    $address    = ((hexdec($dumpline[1]) * 256) + (int)($dumpline[2]));
                    //$recordtype = sprintf("%'.02X", ($dumpline[4]));
                    $recordtype = $lineff[6].$lineff[7];
                    # Verify checksum
                    $checksum   = (hexdec($lineff));
                    $chk = $checksum/2;
                    //$checksum  &= 0x0FF;
                    $tst = $checksum/$chk;
                    if ($tst != 2){
                        //error in checksum
                        Msg('Checksum error !!! Bad Original dump data !!!');
                        return '';
                    }
                    //Data
                    if ($recordtype == '00'){
                        $address += $addressbase;
                        $bin = substr($lineff, 8, ($bytecount)*2);
                        $originaldata .= $bin;
                        
                        $address += ($bytecount);
                        
                        $lastaddress = $address;
                    } elseif($recordtype == '01'){
                        //EOF
                        break;
                    } elseif($recordtype == '02'){
                        if (($bytecount == 2) and ($address == 0)){
                            $addressbase = $dumpline[5] * 16;   
                            if ($lastaddress + 1 < $addressbase){
                                $originaldata .= str_repeat('00', $addressbase-($lastaddress+1)-1);
                            }
                        } else {
                            //error
                            Msg('Error !!! Bad Original dump data !!!');
                            Msg('line = '.$line.' / recordtype = 02 / bytecount != 2 or address != 0');
                            return '';
                        }
                    } elseif($recordtype == '03'){
                        Msg('Start of new segment');

                    }elseif ($recordtype == '04'){
                        if (($bytecount == 2) and ($address == 0)){
                            # Don't take care of upper number in address (remove 0x80 in 0x80000000, 0xA0 in 0xA0000000, ...)
                            # Normal address base is "addressbase = (dumpline[4] * 0x100 + dumpline[5]) * 0x10000"
                            $addressbase = $dumpline[5] * 65536;
                            if ($lastaddress + 1 < $addressbase){
                                $lastaddress = $addressbase << 16;                                    
                            }
                        } else {
                            Msg('Error !!! Bad Original dump data !!!');
                            Msg('line = '.$line.' / recordtype = 04 / bytecount != 2 or address != 0');
                            return '';
                        }

                    } else {
                        Msg('Error !!! Bad Original dump data !!!');
                        Msg('line = '.$line.' / Unknow recordtype 0x'.$recordtype);
                        return '';
                    }
                } else {
                    Msg('Error !!! Bad Original dump data !!!');
                    Msg('PROBABLY a BIN file');
                    Msg('line = '.$line.' / len(line) = '.strlen($line).' / bytecount + 5 = '.($bytecount+5));
                    return file_get_contents($filename);
                }
            }
            $dumpreadsize = round(strlen($originaldata) / 2);
            $dumpdatasize = strlen($originaldata);
        
            //fclose($this->fh);
        }elseif(substr($line,0, 16) == 'S00600004844521B'){
            Msg ('Reading '.round($dumpfilesize/1024/1024, 2).'mB in Motorola s19 format');
            # Read Motorola s19 original dump line by line
            fseek($this->fh, 0);
            while (($line = fgets($this->fh)) !== false) {
                $dumpline = rtrim($line);
                $line = $dumpline;
                # Parse data in the motorola record
                # S31500000000480110F200000000480003C6000000008E
                # STBBAAAAAAAAHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHCC
                # S = startcode, T = recordtype, BB = bytecount, AAAAAAAA = address, HHHH = data, CC = checksum
                if (substr($dumpline, 0, 1) == 'S'){
                    $dumpline = unpack('C*', pack("H*" , substr($line, 2)));
                    //$dumpline = rekeyarray($dumpline);
                    //$dumpline = array('B', pack("H*" , substr($line, 2)));
                    $bytecount = $dumpline[1];
                    $address = $lastaddress;
                    # 16-bit Address format
                    if (substr($line, 0, 2) == 'S1'){
                        $address = $dumpline[2] * 0x100 + $dumpline[3];
                        if ($lastaddress + 1 < $address){
                            # Fill from last address to new address base
                            for($x = $lastaddress; $x<$address; $x++){
                                $originaldata .= '0';
                                $originaldata .= '0';
                                for($x=4; $x<(4+$bytecount-2-1); $x++){
                                    $characters = sprintf("%'.02X", $dumpline[$x]);
                                    if (strlen($characters) == 1){
                                        $originaldata .= '0';
                                        $originaldata .= $characters;
                                    } else {
                                        $originaldata .= $characters[0];
                                        $originaldata .= $characters[1];
                                    }
                                    $address++;
                                }
                            }
                        }
                    } elseif(substr($line, 0, 2) =='S2'){
                        $address = $dumpline[2] * 0x10000 + $dumpline[3] * 0x100 + $dumpline[4];
                        if ($lastaddress + 1 < $address){
                            # Fill from last address to new address base
                            $originaldata .= str_repeat('00', ($address-$lastaddress)-1);
                            /*for($x=$lastaddress; $x<$address; $x++){
                                $originaldata .= '0';
                                $originaldata .= '0';
                            }*/
                        }
                        for($x=5; $x<($bytecount); $x++){
                            $characters = sprintf("%'.02X", $dumpline[$x]);
                            if (strlen($characters) == 1){
                                $originaldata .= '0';
                                $originaldata .= $characters;
                            } else {
                                $originaldata .= $characters[0];
                                $originaldata .= $characters[1];
                            }
                            $address++;
                        }
                    }elseif(substr($line, 0, 2) =='S3'){
                        # 32-bit Address format
                        # Don't take care of upper number in address (remove 0x80 in 0x80000000, 0xA0 in 0xA0000000, ...)
                        # Normal address base is "address = (dumpline[1] * 0x100 + dumpline[2]) * 0x10000 + (dumpline[3] * 0x100 + dumpline[4])"
                        $address = $dumpline[3] * 0x10000 + $dumpline[4] * 0x100 + $dumpline[5];
                        if ($lastaddress + 1 < $address){
                            # Fill from last address to new address base
                            //for($x=$lastaddress; $x<$address; $x++){
                            //    $originaldata .= $characters[0];
                            //    $originaldata .= $characters[0];
                            //}
                            $lastaddress = $address << 16;
                        }
                        for($x=5; $x<($bytecount); $x++){
                            $characters = sprintf("%'.02X", $dumpline[$x]);
                            if (strlen($characters) == 1){
                                $originaldata .= '0';
                                $originaldata .= $characters;
                            } else {
                                $originaldata .= $characters[0];
                                $originaldata .= $characters[1];
                            }
                            $address++;
                        }
                        $lastaddress = $address;
                    }
                    $dumpreadsize = round(strlen($originaldata) / 2);
                    $dumpdatasize = strlen($originaldata);    
                }else{
                    # Unsupported file format
                    Msg('Error !!! Original dump of unsupported format !!!');
                    break;
                } 
            }
            fclose($this->fh);
        } else {
            Msg('already in BIN format');
            return file_get_contents($filename);
        }
        Msg('Took '.microtime(true)-$start_time.' secs');
        return hex2bin($originaldata);
    }
}