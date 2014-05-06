<?php

namespace MoneyMedia\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Process\Process;

use SebastianBergmann\Git;


class MergeCommand extends Command
{
    protected function configure() {
        parent::configure();
        $this
            ->setName('merge')
            ->setDescription('Initiate a merge from one branch to another (iff they both exist)')
            ->addArgument(
                'repo',
                InputArgument::REQUIRED,
                'path to a Composerized application'
            )
            ->addArgument(
                'src',
                InputArgument::REQUIRED,
                'Merge from this branch'
            )
            ->addArgument(
                'dst',
                InputArgument::REQUIRED,
                'Merge into this branch'
            )
            ->addOption(
               'dry-run',
               null,
               InputOption::VALUE_NONE,
               'If set, the task will not actually merge branches'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->getFormatter()->setStyle('head', new OutputFormatterStyle('white', 'black', array('bold')));
        $output->getFormatter()->setStyle('plain', new OutputFormatterStyle('white'));
        $output->getFormatter()->setStyle('pass', new OutputFormatterStyle('green'));
        $output->getFormatter()->setStyle('fail', new OutputFormatterStyle('red', 'yellow', array('bold', 'blink')));
        $output->getFormatter()->setStyle('warn', new OutputFormatterStyle('yellow'));

        $repo = $input->getArgument('repo');
        $refspec = $input->getArgument('refspec');

        $composer = `which composer`;
        if(!$composer) {
            $output->writeln('<error>Composer not found!</error>');
            return;
        }
        $cwd_orig = getcwd();

        $vendor_path = "$repo/vendor";

        if(!is_dir($vendor_path)) {
            $output->writeln("<error>Couldn't chdir to $vendor_path!</error>");
            return;
        }

        // composer install
        if(!chdir($repo)) {
            $output->writeln("<error>Couldn't chdir to $repo!</error>");

        }
        $repo = getcwd(); // real path to repo

        $composerGit = new Git($repo); // checkout tag
        $composerGit->checkout($refspec);
        $process = new Process('composer install -n');
        $process->setTimeout(3600);
        $process->run(function ($type, $buffer) use($output) {
            $output->write($buffer);
        });

        if(!chdir('vendor')) {
            $output->writeln("<error>Couldn't chdir to $vendor_path!</error>");
        }

        $finder = new Finder();
        $finder->in('.')->directories()->depth('== 1');

        $repos = array();
        foreach ($finder as $file) {
            if(is_dir($file->getRealpath().'/.git')) {
                $repos[$file->getRelativePathname()] = $file->getRealpath();
            }
        }

        $pkg_width = array_reduce(array_keys($repos), function($x,$y) { return max(strlen($y),$x); });

        $queries = array(
            array('HEAD','master'),
            array('master','develop'),
        );
        $results = array();


        //$repos = array_slice($repos, 0, 4);

        foreach($repos as $name => $path) {
            $git = new Git($path);
            $tag = trim(`git --work-tree="$path" --git-dir="$path/.git" describe`);
            // fixme only output debugging info if requested
            $output->writeln($name);
            $result = array();
            $result['tag'] = $tag;
            foreach($queries as $pair) {
                $key = implode('..', $pair);
                foreach($pair as &$branch) {
                    if($branch == 'HEAD') {
                        $branch = $tag;
                    } else {
                        $git->checkout($branch); // diffing doesn't work otherwise; no local branches!
                        $branch = "origin/$branch";
                    }
                }
                list($a, $b) = $pair;
                $result[$key] = strlen($git->getDiff("$a", "$b"));
            }
            $result['name'] = $name;
            $results[$name] = $result;
            $git->checkout($tag);
        }

        $widths = array();
        array_walk($queries, function(&$item) { $item = implode('..', $item); });
        array_unshift($queries, 'name', 'tag');

        array_unshift($results, array_combine($queries, $queries)); // put column heads in
        foreach($queries as $query) {
            $widths[$query] = array_reduce(array_column($results, $query), function($x,$y) { return max(strlen($y),$x); });
        }

        print_r($results);
        print_r($queries);
        print_r($widths);

        foreach($results as $rowIdx => $result){
            $string = '';
            foreach($queries as $colIdx => $k) {
                $v = $result[$k];
                if($rowIdx && $colIdx && $k != 'tag') {
                    $tag = $v===0 ? "pass" : "fail";
                    $v = strtoupper($tag);
                } elseif(!$rowIdx) {
                    $tag = 'head';
                } else {
                    $tag = 'plain';
                }
                $string .= sprintf("<$tag>%' {$widths[$k]}s</$tag> ", $v);
            }
            $output->writeln($string);
        }

    }
}