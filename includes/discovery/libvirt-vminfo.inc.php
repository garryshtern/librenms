<?php

// FIXME should do the deletion etc in a common file perhaps? like for the sensors
// Try to discover Libvirt Virtual Machines.
if ($config['enable_libvirt'] == '1' && $device['os'] == 'linux') {
    $libvirt_vmlist = array();

    $ssh_ok = 0;

    $userHostname = $device['hostname'];
    if (isset($config['libvirt_username'])) {
        $userHostname = $config['libvirt_username'].'@'.$userHostname;
    }

    foreach ($config['libvirt_protocols'] as $method) {
        if (strstr($method, 'qemu')) {
            $uri = $method.'://'.$userHostname.'/system';
        } else {
            $uri = $method.'://'.$userHostname;
        }

        if (strstr($method, 'ssh') && !$ssh_ok) {
            // Check if we are using SSH if we can log in without password - without blocking the discovery
            // Also automatically add the host key so discovery doesn't block on the yes/no question, and run echo so we don't get stuck in a remote shell ;-)
            exec('ssh -o "StrictHostKeyChecking no" -o "PreferredAuthentications publickey" -o "IdentitiesOnly yes" '.$userHostname.' echo -e', $out, $ret);
            if ($ret != 255) {
                $ssh_ok = 1;
            }
        }

        if ($ssh_ok || !strstr($method, 'ssh')) {
            // Fetch virtual machine list
            unset($domlist);
            exec($config['virsh'].' -c '.$uri.' list', $domlist);

            foreach ($domlist as $dom) {
                list($dom_id,) = explode(' ', trim($dom), 2);

                if (is_numeric($dom_id)) {
                    // Fetch the Virtual Machine information.
                    unset($vm_info_array);
                    exec($config['virsh'].' -c '.$uri.' dumpxml '.$dom_id, $vm_info_array);

                    // <domain type='kvm' id='3'>
                    // <name>moo.example.com</name>
                    // <uuid>48cf6378-6fd5-4610-0611-63dd4b31cfd6</uuid>
                    // <memory>1048576</memory>
                    // <currentMemory>1048576</currentMemory>
                    // <vcpu>8</vcpu>
                    // <os>
                    // <type arch='x86_64' machine='pc-0.12'>hvm</type>
                    // <boot dev='hd'/>
                    // </os>
                    // <features>
                    // <acpi/>
                    // (...)
                    // Convert array to string
                    unset($vm_info_xml);
                    foreach ($vm_info_array as $line) {
                        $vm_info_xml .= $line;
                    }

                    $xml = simplexml_load_string('<?xml version="1.0"?> '.$vm_info_xml);
                    d_echo($xml);

                    $vmwVmDisplayName = $xml->name;
                    $vmwVmGuestOS     = '';
                    // libvirt does not supply this
                    $vmwVmMemSize = ($xml->currentMemory / 1024);
                    exec($config['virsh'].' -c '.$uri.' domstate '.$dom_id, $vm_state);
                    $vmwVmState = ucfirst($vm_state[0]);
                    $vmwVmCpus  = $xml->vcpu;

                    // Check whether the Virtual Machine is already known for this host.
                    $result = dbFetchRow("SELECT * FROM `vminfo` WHERE `device_id` = ? AND `vmwVmVMID` = ? AND `vm_type` = 'libvirt'", array($device['device_id'], $dom_id));
                    if (count($result['device_id']) == 0) {
                        $inserted_id = dbInsert(array('device_id' => $device['device_id'], 'vm_type' => 'libvirt', 'vmwVmVMID' => $dom_id, 'vmwVmDisplayName' => mres($vmwVmDisplayName), 'vmwVmGuestOS' => mres($vmwVmGuestOS), 'vmwVmMemSize' => mres($vmwVmMemSize), 'vmwVmCpus' => mres($vmwVmCpus), 'vmwVmState' => mres($vmwVmState)), 'vminfo');
                        echo '+';
                        log_event("Virtual Machine added: $vmwVmDisplayName ($vmwVmMemSize MB)", $device, 'vm', $inserted_id);
                    } else {
                        if ($result['vmwVmState'] != $vmwVmState
                            || $result['vmwVmDisplayName'] != $vmwVmDisplayName
                            || $result['vmwVmCpus'] != $vmwVmCpus
                            || $result['vmwVmGuestOS'] != $vmwVmGuestOS
                            || $result['vmwVmMemSize'] != $vmwVmMemSize
                        ) {
                            dbUpdate(array('vmwVmState' => mres($vmwVmState), 'vmwVmGuestOS' => mres($vmwVmGuestOS), 'vmwVmDisplayName' => mres($vmwVmDisplayName), 'vmwVmMemSize' => mres($vmwVmMemSize), 'vmwVmCpus' => mres($vmwVmCpus)), 'vminfo', "device_id=? AND vm_type='libvirt' AND vmwVmVMID=?", array($device['device_id'], $dom_id));
                            echo 'U';
                            // FIXME eventlog
                        } else {
                            echo '.';
                        }
                    }

                    // Save the discovered Virtual Machine.
                    $libvirt_vmlist[] = $dom_id;
                }//end if
            }//end foreach
        }//end if

        // If we found VMs, don't cycle the other protocols anymore.
        if (count($libvirt_vmlist)) {
            break;
        }
    }//end foreach

    // Get a list of all the known Virtual Machines for this host.
    $sql = "SELECT id, vmwVmVMID, vmwVmDisplayName FROM vminfo WHERE device_id = '".$device['device_id']."' AND vm_type='libvirt'";

    foreach (dbFetchRows($sql) as $db_vm) {
        // Delete the Virtual Machines that are removed from the host.
        if (!in_array($db_vm['vmwVmVMID'], $libvirt_vmlist)) {
            dbDelete('vminfo', '`id` = ?', array($db_vm['id']));
            echo '-';
            log_event('Virtual Machine removed: '.$db_vm['vmwVmDisplayName'], $device, 'vm', $db_vm['id']);
        }
    }

    echo "\n";
}//end if
