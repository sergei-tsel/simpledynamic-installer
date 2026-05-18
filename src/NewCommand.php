<?php

namespace Simpledynamic\Installer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    private Filesystem $fs;

    /** @var QuestionHelper $helper */
    private QuestionHelper $helper;

    protected function configure(): void
    {
        $this->setName('new')
             ->setDescription('Создаёт новый проект на Simpledynamic')
             ->addArgument('name', InputArgument::OPTIONAL, 'Название проекта')
             ->addOption('dev', 'd', InputOption::VALUE_NONE, 'Установить с dev-зависимостями')
             ->addOption('git', 'g', InputOption::VALUE_NONE, 'Инициализировать репозиторий Git')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Перезаписать существующую папку');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->fs = new Filesystem();

        $this->helper = $this->getHelper('question');

        $projectName = $this->getProjectName($input, $output);
        if (!$projectName) {
            return Command::FAILURE;
        }

        if (!$this->handleExistingDirectory($projectName, $input, $output)) {
            return Command::FAILURE;
        }

        $installDev = $this->shouldInstallDevDependencies($input, $output);
        $initGit = $this->shouldInitializeGit($input, $output);

        if (!$this->cloneTemplate($projectName, $output)) {
            return Command::FAILURE;
        }

        if (!$this->installDependencies($projectName, $installDev, $output)) {
            return Command::FAILURE;
        }

        if ($initGit && !$this->initializeGit($output)) {
            return Command::FAILURE;
        }

        $this->renderSuccessMessage($projectName, $output);

        return Command::SUCCESS;
    }

    /**
     * Получить название проекта
     */
    private function getProjectName(InputInterface $input, OutputInterface $output): ?string
    {
        $name = $input->getArgument('name');

        if (!$name) {
            $question = new Question('📦 Название проекта: ', 'my-app');
            $name = $this->helper->ask($input, $output, $question);
        }

        return $name;
    }

    /**
     * Обработать случай, когда указанная дирректория уже существует
     */
    private function handleExistingDirectory(string $projectName, InputInterface $input, OutputInterface $output): bool
    {
        if (!$this->fs->exists($projectName)) {
            return true;
        }

        if ($input->getOption('force')) {
            $this->fs->remove($projectName);
            return true;
        }

        $question = new ConfirmationQuestion(
            "\n<comment>⚠️  Папка '$projectName' уже существует.</comment>\n".
            "<comment>Перезаписать? (y/N)</comment> <fg=yellow>[N]</> ",
            false
        );

        if (!$this->helper->ask($input, $output, $question)) {
            $output->writeln("❌ Операция отменена.");
            return false;
        }

        $this->fs->remove($projectName);

        return true;
    }

    /**
     * Запросить опицю установки dev-зависимостей
     */
    private function shouldInstallDevDependencies(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->hasParameterOption(['--dev', '-d'])) {
            return true;
        }

        $question = new ConfirmationQuestion(
            '<info>🔧 Установить с dev-зависимостями? (y/N)</info> <fg=yellow>[N]</> ',
            false
        );

        return $this->helper->ask($input, $output, $question);
    }

    /**
     * Запросить опицю инициализации Git репозитория
     */
    private function shouldInitializeGit(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->hasParameterOption(['--git', '-g'])) {
            return true;
        }

        $question = new ConfirmationQuestion(
            '<info>📁 Инициализировать Git? (y/N)</info> <fg=yellow>[N]</> ',
            false
        );

        return $this->helper->ask($input, $output, $question);
    }

    /**
     * Клонировать шаблон проекта
     */
    private function cloneTemplate(string $projectName, OutputInterface $output): bool
    {
        $output->writeln("📥 <info>Клонируем шаблон...</info>");

        $cloneCmd = "git clone https://github.com/sergei-tsel/simpledynamic \"$projectName\" --quiet";
        $process = Process::fromShellCommandline($cloneCmd);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            $output->writeln("<error>❌ Ошибка при клонировании репозитория.</error>");
            return false;
        }

        $this->fs->remove("$projectName/.git");
        chdir($projectName);

        return true;
    }

    /**
     * Установить зависимости с помощью Composer
     */
    private function installDependencies(string $projectName, bool $withDev, OutputInterface $output): bool
    {
        $output->writeln("📦 <info>Устанавливаем зависимости...</info>");

        $composerCmd = ['composer', 'install'];

        if (!$withDev) {
            $composerCmd[] = '--no-dev';
        }

        $composerCmd[] = '--optimize-autoloader';

        $composer = Process::fromShellCommandline(implode(' ', $composerCmd));
        $composer->setTimeout(300);

        $composer->run(function ($type, $buffer) use ($output) {
            if (Process::ERR === $type) {
                $output->write("<error>$buffer</error>");
            } else {
                $output->write($buffer);
            }
        });

        if (!$composer->isSuccessful()) {
            $output->writeln("<error>❌ Ошибка при установке Composer-зависимостей.</error>");
            return false;
        }

        return true;
    }

    /**
     * Инициализировать Git репозиторий
     */
    private function initializeGit(OutputInterface $output): bool
    {
        $output->writeln("🔧 <info>Инициализируем Git...</info>");

        $commands = [
            'git init --quiet',
            'git add -A --quiet',
            'git commit -m "Initial commit" --quiet',
        ];

        foreach ($commands as $cmd) {
            $process = Process::fromShellCommandline($cmd);
            $process->run();

            if (!$process->isSuccessful()) {
                $output->writeln("<error>❌ Ошибка при инициализации Git.</error>");

                return false;
            }
        }

        return true;
    }

    /**
     * Вывести сообщение об успешном создании проекта
     */
    private function renderSuccessMessage(string $projectName, OutputInterface $output): void
    {
        $output->writeln("\n🎉 <info>Проект '$projectName' успешно создан!</info>");
        $output->writeln("");
        $output->writeln("Перейди в папку и запусти сервер:");
        $output->writeln("  <fg=yellow;bg=black>cd $projectName</>");
        $output->writeln("  <fg=yellow;bg=black>php -S localhost:8000 -t public</>");
        $output->writeln("");
    }
}
