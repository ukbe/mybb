#!/bin/bash

###################################################################################################
#                                                                                                 #
#  Step 1 :                                                                                       #
#                                                                                                 #
#  Before running this script make sure you have configured aws cli tool and make sure to set;    #
#                                                                                                 #
#   - AWS Access Key ID                                                                           #
#   - AWS Secret Access Key                                                                       #
#   - Default region name                                                                         #
#   - Default output format                                                                       #
#                                                                                                 #
#  Example:                                                                                       #
#                                                                                                 #
#   $ aws configure                                                                               #
#   AWS Access Key ID [None]: AKIAIOSFODNN7EXAMPLE                                                #
#   AWS Secret Access Key [None]: wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY                        #
#   Default region name [None]: us-west-2                                                         #
#   Default output format [None]: json                                                            #
#                                                                                                 #
#  Step 2 :                                                                                       #
#                                                                                                 #
#  Change stack parameters in mybb-cfn-skeleton.json file. ParAdminEmail parameter                #
#  does not have a default value and should be set before running this script.                    #
#                                                                                                 #
#                                                                                                 #
###################################################################################################

# Create a keypair 
aws ec2 create-key-pair --key-name mybb-ssh-key --query 'KeyMaterial' --output text > ./mybb-ssh-key.pem

# Set ssh key permissions
chmod 400 ./mybb-ssh-key.pem

# Trigger stack creation
aws cloudformation create-stack --cli-input-json "$(< mybb-cfn-skeleton.json)" --template-body "$(< mybb-cfn-template.json)"
                               
