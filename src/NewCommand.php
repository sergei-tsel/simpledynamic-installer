<?php

namespace Simpledynamic\Installer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand; // ← Новый атрибут
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'new',
    description: 'Создаёт новый проект на Simpledynamic'
)]
class NewCommand extends Command
{
    protected function configure(): void
    {
        // Описание уже указано в атрибуте, но можно добавить аргументы/опции
        $this->addArgument('name', InputArgument::OPTIONAL, 'Название проекта')
             ->addOption('dev', null, InputOption::VALUE_NONE, 'Установить с dev-зависимостями')
             ->addOption('git', null, InputOption::VALUE_NONE, 'Инициализировать репозиторий Git')
             ->addOption('force', null, InputOption::VALUE_NONE, 'Перезаписать существующую папку');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fs = new Filesystem();

        // Интерактивный ввод, если название проекта не указано
        $projectName = $input->getArgument('name');

        if (!$projectName) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question('📦 Название проекта: ', 'my-app');
            $projectName = $helper->ask($input, $output, $question);
        }

        // Проверка на существование папки
        if ($fs->exists($projectName)) {
            if (!$input->getOption('force')) {
                /** @var QuestionHelper $helper */
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion(
                    "<comment>Папка '$projectName' уже существует. Перезаписать? (y/N)</comment> ",
                    false
                );

                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln("❌ Операция отменена.");
                    return Command::FAILURE;
                }
            }

            $fs->remove($projectName);
        }

        $output->writeln("🚀 <info>Создаём проект: $projectName</info>");

        // Клонируем шаблон
        $cloneCmd = "git clone https://github.com/sergei-tsel/simpledynamic \"$projectName\" --quiet";
        $process = Process::fromShellCommandline($cloneCmd);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            $output->writeln("<error>❌ Ошибка при клонировании репозитория.</error>");
            return Command::FAILURE;
        }

        $fs->remove("$projectName/.git");

        // Переходим в папку проекта
        chdir($projectName);

        // Установка зависимостей
        $composerCmd = ['composer', 'install'];

        if (!$input->getOption('dev')) {
            $composerCmd[] = '--no-dev';
        }

        $composerCmd[] = '--optimize-autoloader';

        $composer = Process::fromShellCommandline(implode(' ', $composerCmd));
        $composer->setTimeout(300);

        $output->writeln("📦 <info>Устанавливаем зависимости...</info>");
        $composer->run(function ($type, $buffer) use ($output) {
            if (Process::ERR === $type) {
                $output->write("<error>$buffer</error>");
            } else {
                $output->write($buffer);
            }
        });

        if (!$composer->isSuccessful()) {
            $output->writeln("<error>❌ Ошибка при установке Composer-зависимостей.</error>");
            return Command::FAILURE;
        }

        // Инициализация Git
        if ($input->getOption('git')) {
            $output->writeln("🔧 <info>Инициализируем Git...</info>");
            $gitInit = Process::fromShellCommandline('git init --quiet');
            $gitInit->run();

            $gitAdd = Process::fromShellCommandline('git add -A --quiet');
            $gitAdd->run();

            $gitCommit = Process::fromShellCommandline('git commit -m "Initial commit" --quiet');
            $gitCommit->run();
        }

        // Готово!
        $output->writeln("\n🎉 <info>Проект '$projectName' успешно создан!</info>");
        $output->writeln("");
        $output->writeln("Перейди в папку и запусти сервер:");
        $output->writeln("  <fg=yellow;bg=black>cd $projectName</>");
        $output->writeln("  <fg=yellow;bg=black>php -S localhost:8000 -t public</>");
        $output->writeln("");

        return Command::SUCCESS;
    }
}
