<?php

declare(strict_types=1);

namespace Marcus\PhpFinTsWrapper;

use AssertionError;
use Fhp\BaseAction;
use Fhp\FinTs;
use Fhp\Model\FlickerTan\SvgRenderer;
use Fhp\Model\FlickerTan\TanRequestChallengeFlicker;
use Fhp\Model\TanRequestChallengeImage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class ActionService
{
    private ?OutputInterface $output;

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function login(FinTs $finTs, string $persistedInstanceFilePath = null): void
    {
        $login = $finTs->login();

        if ($login->needsTan()) {
            $this->handleStrongAuthentication($finTs, $login);
        }

        $this->persist($finTs, $persistedInstanceFilePath);
    }

    public function action(FinTs $finTs, BaseAction $action, string $persistedInstanceFilePath = null): void
    {
        $finTs->execute($action);

        if ($action->needsTan()) {
            $this->handleStrongAuthentication($finTs, $action);
        }

        $this->persist($finTs, $persistedInstanceFilePath);
    }

    protected function persist(FinTs $finTs, string $persistedInstanceFilePath = null): void
    {
        if ($persistedInstanceFilePath !== null) {
            file_put_contents($persistedInstanceFilePath, base64_encode($finTs->persist()));
        }
    }

    /**
     * This function as well as handleTan() and handleDecoupled() below are key to how FinTS works in times of PSD2
     * regulations.
     * Most actions like wire transfers, getting statements and even logging in can ask for strong authentication (a TAN or
     * some form of confirmation on a "decoupled" device that the user has access to), but won't always. Whether strong
     * authentication required depends on the kind of action, when it was last executed, other parameters like the amount
     * (of a wire transfer) or time span (of a statement request) and generally the security concept of the particular bank.
     * The authentication requirements may or may not be consistent with the kinds of authentication that the same bank
     * requires for the same action in the web-based online banking interface. Also, banks may change these requirements
     * over time, so just because your particular bank does or does not need a TAN for login today does not mean that it
     * stays that way. There is a general tendency towards less intrusive strong authentication, i.e. requiring it for fewer
     * actions (based on heuristics), less often (e.g. only every 90 days) or in a decoupled mode where the user only needs
     * to tap a single button.
     *
     * The strong authentication can be implemented in many different ways. Each application that uses the phpFinTS
     * library has to implement its own way of asking users for a TAN or for decoupled confirmation, which varies depending
     * on its user interfaces. The implementation does not have to be in a single function like this -- it can be inlined
     * with the calling code, or live elsewhere. The TAN/confirmation can be obtained while the same PHP script is still
     * running (i.e. handleStrongAuthentication() is a blocking function that only returns once the authentication is done,
     * which is useful for a CLI application), but it is also possible to interrupt the PHP execution entirely while asking
     * for the TAN/confirmation and resume it later (which is useful for a web application).
     *
     * @param BaseAction $action Some action that requires strong authentication.
     */
    public function handleStrongAuthentication(FinTs $finTs, BaseAction $action): void
    {
        if ($finTs->getSelectedTanMode()?->isDecoupled() === true) {
            $this->handleDecoupled($finTs, $action);
        } else {
            $this->handleTan($finTs, $action);
        }
    }

    /**
     * This function handles strong authentication for the case where the user needs to enter a TAN into the PHP
     * application.
     * @param BaseAction $action Some action that requires a TAN.
     */
    protected function handleTan(FinTs $finTs, BaseAction $action): void
    {
        // Find out what sort of TAN we need, tell the user about it.
        $tanRequest = $action->getTanRequest();
        $this->output('The bank requested a TAN.');
        if ($tanRequest?->getChallenge() !== null) {
            $this->output(' Instructions: ' . $tanRequest?->getChallenge());
        }

        if ($tanRequest?->getTanMediumName() !== null) {
            $this->output('Please use this device: ' . $tanRequest?->getTanMediumName());
        }

        // Challenge Image for PhotoTan/ChipTan
        if ($tanRequest?->getChallengeHhdUc() instanceof \Fhp\Syntax\Bin) {
            try {
                $flicker = new TanRequestChallengeFlicker($tanRequest?->getChallengeHhdUc());
                $this->output('There is a challenge flicker.');
                // save or output svg
                $flickerPattern = $flicker->getFlickerPattern();
                // other renderers can be implemented with this pattern
                $svg = new SvgRenderer($flickerPattern);
                $this->output($svg->getImage());
            } catch (InvalidArgumentException) {
                // was not a flicker
                $challengeImage = new TanRequestChallengeImage($tanRequest?->getChallengeHhdUc());
                $this->output('There is a challenge image.');
                // Save the challenge image somewhere
                // Alternative: HTML sample code
                $this->output(
                    '<img src="data:' . htmlspecialchars($challengeImage->getMimeType()) . ';base64,' . base64_encode(
                        $challengeImage->getData()
                    ) . '" />'
                );
            }
        }

        // Ask the user for the TAN. ----------------------------------------------------------------------------------------
        // IMPORTANT: In your real application, you cannot use fgets(STDIN) of course (unless you're running PHP only as a
        // command line application). So you instead want to send a response to the user. This means that, after executing
        // the first half of handleTan() above, your real application will terminate the PHP process. The second half of
        // handleTan() will likely live elsewhere in your application code (i.e. you will have two functions for the TAN
        // handling, not just one like in this simplified example). You *only* need to carry over the $persistedInstance
        // and the $persistedAction (which are simple strings) by storing them in some database or file where you can load
        // them again in a new PHP process when the user sends the TAN.
        $this->output("Please enter the TAN:");
        $tan = trim(fgets(STDIN));

        $this->output(sprintf('Submitting TAN: %s', $tan));
        $finTs->submitTan($action, $tan);
    }

    /**
     * This function handles strong authentication for the case where the user needs to confirm the action on another
     * device. Note: Depending on the banks you need compatibility with you may not need to implement decoupled
     * authentication at all, i.e. you could filter out any decoupled TanModes when letting the user choose.
     * @param BaseAction $action Some action that requires decoupled authentication.
     */
    protected function handleDecoupled(FinTs $finTs, BaseAction $action): void
    {
        $tanMode = $finTs->getSelectedTanMode();
        $tanRequest = $action->getTanRequest();
        $this->output('The bank requested authentication on another device.');
        if ($tanRequest?->getChallenge() !== null) {
            $this->output(' Instructions: ' . $tanRequest?->getChallenge());
        }

        if ($tanRequest?->getTanMediumName() !== null) {
            $this->output('Please check this device: ' . $tanRequest?->getTanMediumName());
        }

        // IMPORTANT: In your real application, you don't have to use sleep() in PHP. You can persist the state in the same
        // way as in handleTan() and restore it later. This allows you to use some other timer mechanism (e.g. in the user's
        // browser). This PHP sample code just serves to show the *logic* of the polling. Alternatively, you can even do
        // without polling entirely and just let the user confirm manually in all cases (i.e. only implement the `else`
        // branch below).
        if ($tanMode?->allowsAutomatedPolling() === true) {
            $this->output("Polling server to detect when the decoupled authentication is complete.");
            sleep($tanMode?->getFirstDecoupledCheckDelaySeconds());
            for ($attempt = 0;
                $tanMode?->getMaxDecoupledChecks() === 0 || $attempt < $tanMode?->getMaxDecoupledChecks();
                ++$attempt
            ) {
                if ($finTs->checkDecoupledSubmission($action)) {
                    $this->output("Confirmed.");
                    return;
                }

                $this->output("Still waiting...");
                sleep($tanMode?->getPeriodicDecoupledCheckDelaySeconds());
            }

            throw new RuntimeException(sprintf('Not confirmed after %d attempts, which is the limit.', $attempt));
        }

        if ($tanMode?->allowsManualConfirmation() === true) {
            do {
                $this->output(
                    "Please type 'done' and hit Return when you've completed the authentication on the other device."
                );
                while (trim(fgets(STDIN)) !== 'done') {
                    $this->output("Try again.");
                }

                $this->output("Confirming that the action is done.");
            } while (! $finTs->checkDecoupledSubmission($action));

            $this->output("Confirmed");
        } else {
            throw new AssertionError('Server allows neither automated polling nor manual confirmation');
        }
    }

    private function output(string $string): void
    {
        if ($this->output instanceof OutputInterface) {
            $this->output->writeln($string);
        } else {
            echo $string . PHP_EOL;
        }
    }
}
