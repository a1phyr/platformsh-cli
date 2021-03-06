<?php
namespace Platformsh\Cli\Command\User;

use Symfony\Component\Console\Input\InputArgument;

/**
 * This command is the same as user:add, with different documentation.
 */
class UserUpdateCommand extends UserAddCommand
{

    protected function configure()
    {
        $this
            ->setName('user:update')
            ->setDescription('Update user role(s) on a project')
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address");

        $this->addRoleOption();
        $this->addProjectOption();
        $this->addWaitOptions();

        $this->addExample('Make Bob an admin on the "develop" and "stage" environments', 'bob@example.com -r develop:a,stage:a');
        $this->addExample('Make Charlie a contributor on all existing environments', 'charlie@example.com -r %:c');
        $this->addExample('Make Damien an admin on "master" and all (existing) environments starting with "pr-"', 'damien@example.com -r master:a -r pr-%:a');
    }
}
