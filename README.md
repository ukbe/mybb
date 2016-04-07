# myBB CloudFormation Template

## Description

This project provides a CloudFormation template which provides a "scalable" and "high available" myBB Forum application.


## Implementation

There are two different ways you can create a stack using this template.

#### Using Management Console

Creating a stack using the management console is pretty straightforward. Tihs step requires you to create an ssh key pair beforehand.

1. Log in to management console and go to CloudFormation service page.
2. Click Create Stack link.
3. Select Upload a template to Amazon S3 option and click Choose File.
4. Browse for mybb-cfn-template.json file that you previously downloaded from this repository.
5. Click Next.
6. Fill in the parameters form and make necessary changes. All fields are required. Click Next
7. Click Next on Options page.
8. On the Review page you will see checkbox with a message stating "I acknowledge that this template might cause AWS CloudFormation to create IAM resources.". You should enable this checkbox to allow the creation of IAM roles required to retrieve CloudWatch logs.
9. Click Create.

This template takes up to 30 minutes to complete.

#### Using AWS CLI

CLI method is more simple but it requires you to install and configre AWS CLI on your client machine. Before starting steps below make sure to:

- Install AWS CLI
- Configure AWS CLI to set "* *AWS Access Key ID* *", "* *AWS Secret Access Key* *", "* *Default region name* *" and "* *Default output format* *".

When you are ready to go follow steps listed below. Do not forget to edit parameters in **mybb-cfn-skeleton.json** file before running **deploy-cfn-mybb.sh** script. 

```
cd ~
git clone https://github.com/ukbe/mybb.git
cd mybb
nano mybb-cfn-skeleton.json
./deploy-cfn-mybb.sh
```

In order to access your instances you may use the ssh private key automatically created by **deploy-cfn-mybb.sh** script if you haven't changed ParKeyName parameter.

Hope you like it. Good luck!

