<?php declare(strict_types=1);

namespace PHPWander;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPWander\Broker\Broker;
use PHPWander\Analyser\Scope;
use PHPWander\Printer\Printer;

/**
 * @author Pavel Jurásek
 */
class TransitionFunction
{

	/** @var Broker */
	private $broker;

	/** @var Printer */
	private $printer;

	/** @var SourceFunctions */
	private $sourceFunctions;

	/** @var SinkFunctions */
	private $sinkFunctions;

	/** @var SanitizerFunctions */
	private $sanitizerFunctions;

	/** @var TaintFunctions */
	private $taintFunctions;

	public function __construct(
		Broker $broker,
		Printer $printer,
		SourceFunctions $sourceFunctions,
		SinkFunctions $sinkFunctions,
		SanitizerFunctions $sanitizerFunctions,
		TaintFunctions $taintFunctions
	) {
		$this->broker = $broker;
		$this->printer = $printer;
		$this->sourceFunctions = $sourceFunctions;
		$this->sinkFunctions = $sinkFunctions;
		$this->sanitizerFunctions = $sanitizerFunctions;
		$this->taintFunctions = $taintFunctions;
	}

	public function transfer(Scope $scope, Operand $node): int
	{
		if ($node instanceof Literal) {
			return Taint::UNTAINTED;
		} elseif ($node instanceof Operand\Temporary) {
			return $this->transferTemporary($scope, $node);
		} elseif ($node instanceof Operand\Variable) {
			if ($this->isSource($node, $scope, 'userinput')) {
				return Taint::TAINTED;
			}

			return $scope->getVariableTaint($this->printer->printOperand($node, $scope));
		}

		dump($node);
		dump(__METHOD__);

		return Taint::UNKNOWN;
	}

	private function transferTemporary(Scope $scope, Operand\Temporary $node): int
	{
		if ($node->original !== null) {
			return $this->transfer($scope, $node->original);
		}

		$taint = Taint::UNKNOWN;
		foreach ($node->ops as $op) {
			$taint = $this->leastUpperBound($taint, $this->transferOp($scope, $op));
		}

		return $taint;
	}

	public function transferOp(Scope $scope, Op $op, bool $omitSavedAttribute = false): int
	{
		if ($op->hasAttribute(Taint::ATTR) && !$omitSavedAttribute) {
			return (int) $op->getAttribute(Taint::ATTR);
		}

		if ($op instanceof Op\Terminal\Return_ && $op->expr !== null) {
			return $this->transfer($scope, $op->expr);
		} elseif ($op instanceof Op\Expr\FuncCall) {
			if ($op->name instanceof Literal) {
				$funcName = $this->printer->printOperand($op->name, $scope);

				$taintSection = $this->taintFunctions->getTaint($funcName);
				if ($taintSection) {
					$taints = [$taintSection];
					$taint = Taint::TAINTED;
					$type = 'string';
					$op->setAttribute(Taint::ATTR, $taint);
					$op->setAttribute(Taint::ATTR_TAINT, $taints);
					$op->setAttribute('type', $type);
				}

				$source = $this->sourceFunctions->getSource($funcName);
				if ($source) {
					$taints = [$source];
					$taint = Taint::TAINTED;
					$op->setAttribute(Taint::ATTR_SOURCE, $taints);
					$op->setAttribute(Taint::ATTR, $taint);
				}

				$sanitize = $this->sanitizerFunctions->getSanitize($funcName);
				if ($sanitize) {
					$sanitize = [$sanitize];
					$taint = Taint::UNTAINTED;
					$op->setAttribute(Taint::ATTR_SANITIZE, $sanitize);
					$op->setAttribute(Taint::ATTR, $taint);
				}

//				if ($this->sanitizerFunctions->sanitizesFile($funcName)) {
//					$taints = ['file'];
//					$taint = Taint::UNTAINTED;
//					$op->setAttribute(Taint::ATTR_SANITIZE, $taints);
//					$op->setAttribute(Taint::ATTR, $taint);
//
//					return $taint;
//				}

				// sinks should be handled by rules?
				$sink = $this->sinkFunctions->getSink($funcName);
				if ($sink) {
					$sink = [$sink];
					$taint = Taint::TAINTED;
					$op->setAttribute(Taint::ATTR_SINK, $sink);
					$op->setAttribute(Taint::ATTR, $taint);
				}

				if (isset($taint)) {
					return $taint;
				}

//				$function = $this->broker->getFunction($op->name, $scope);
//				$type = $function->getReturnType();

//				return $this->transferType($type);
			} elseif ($op->name instanceof Operand\Variable) {
				// func call on variable, will be handled by rule
			} else {
				dump(__METHOD__);
				dump('?');
				dump($op);
			}
		} elseif ($op instanceof Op\Expr\BinaryOp\Plus) {
			return Taint::UNTAINTED;
		}

		return Taint::UNKNOWN;
	}

	public function transferCast(Scope $scope, Op\Expr\Cast $op): Scope
	{
		$trusted = false;

		if ($op->expr->original instanceof Operand\Variable) {
			$variableName = $this->printer->printOperand($op->expr->original, $scope);

			$trusted = $scope->hasVariableTaint($variableName) && $scope->getVariableTaint($variableName) === Taint::UNTAINTED;
		}

		$taint = Taint::UNTAINTED;

		if ($op instanceof Op\Expr\Cast\String_ && !$trusted) {
			$taint = Taint::TAINTED;
		}

		$op->setAttribute(Taint::ATTR, $taint);

		return $scope;
	}

	public function isTainted(int $taint): bool
	{
		return $taint === Taint::TAINTED || $taint === Taint::BOTH;
	}

	private function setAttributes(Op $node, array $attributes): void
	{
		foreach ($attributes as $key => $value) {
			$node->setAttribute($key, $value);
		}
	}

	public function isSource(Operand $operand, Scope $scope, ?string $section = null): bool
	{
		if ($section === null) {
			$sources = $this->sourceFunctions->getAll();
		} else {
			$sources = $this->sourceFunctions->getSection($section);
		}

		return in_array($this->printer->printOperand($operand, $scope), $sources);
	}

	public function isSink(Operand $operand, Scope $scope, ?string $section = null): bool
	{
		if ($section === null) {
			$sources = $this->sinkFunctions->getAll();
		} else {
			$sources = $this->sinkFunctions->getSection($section);
		}

		return in_array($this->printer->printOperand($operand, $scope), $sources);
	}

	public function isSanitizer(Operand $operand, Scope $scope, ?string $section = null): bool
	{
		if ($section === null) {
			$sources = $this->sanitizerFunctions->getAll();
		} else {
			$sources = $this->sanitizerFunctions->getSection($section);
		}

		return in_array($this->printer->print($operand, $scope), $sources);
	}

	public function leastUpperBound(int $taint, int $transferOp): int
	{
		return max($taint, $transferOp);
	}

	public function isSuperGlobal(Operand\Variable $variable, Scope $scope): bool
	{
		return in_array($this->printer->print($variable, $scope), $this->sourceFunctions->getSection('userinput'));
	}

	public function transferSuperGlobal(Operand\Variable $variable, string $dim): int
	{
		if (
			$variable->name->value === '_SERVER'
			&& !in_array($dim, $this->sourceFunctions->getSection('serverParameters'), true)
		) {
			return Taint::UNTAINTED;
		}

		return Taint::TAINTED;
	}

}
