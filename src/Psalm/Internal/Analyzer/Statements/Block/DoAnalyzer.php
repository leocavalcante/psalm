<?php
namespace Psalm\Internal\Analyzer\Statements\Block;

use PhpParser;
use Psalm\Internal\Analyzer\ScopeAnalyzer;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Clause;
use Psalm\Context;
use Psalm\Internal\Scope\LoopScope;
use Psalm\Type;
use Psalm\Type\Algebra;
use function in_array;
use function array_values;
use function array_filter;
use function array_keys;
use function preg_match;
use function preg_quote;
use function array_merge;

/**
 * @internal
 */
class DoAnalyzer
{
    /**
     * @return void
     */
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Stmt\Do_ $stmt,
        Context $context
    ) {
        $do_context = clone $context;

        $do_context->inside_case = false;

        $codebase = $statements_analyzer->getCodebase();

        if ($codebase->alter_code) {
            $do_context->branch_point = $do_context->branch_point ?: (int) $stmt->getAttribute('startFilePos');
        }

        $loop_scope = new LoopScope($do_context, $context);
        $loop_scope->protected_var_ids = $context->protected_var_ids;

        $suppressed_issues = $statements_analyzer->getSuppressedIssues();

        if (!in_array('RedundantCondition', $suppressed_issues, true)) {
            $statements_analyzer->addSuppressedIssues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressed_issues, true)) {
            $statements_analyzer->addSuppressedIssues(['RedundantConditionGivenDocblockType']);
        }
        if (!in_array('TypeDoesNotContainType', $suppressed_issues, true)) {
            $statements_analyzer->addSuppressedIssues(['TypeDoesNotContainType']);
        }

        $do_context->loop_scope = $loop_scope;

        $statements_analyzer->analyze($stmt->stmts, $do_context);

        if (!in_array('RedundantCondition', $suppressed_issues, true)) {
            $statements_analyzer->removeSuppressedIssues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressed_issues, true)) {
            $statements_analyzer->removeSuppressedIssues(['RedundantConditionGivenDocblockType']);
        }
        if (!in_array('TypeDoesNotContainType', $suppressed_issues, true)) {
            $statements_analyzer->removeSuppressedIssues(['TypeDoesNotContainType']);
        }

        $loop_scope->iteration_count++;

        foreach ($context->vars_in_scope as $var => $type) {
            if ($type->hasMixed()) {
                continue;
            }

            if (isset($do_context->vars_in_scope[$var])) {
                if (!$do_context->vars_in_scope[$var]->equals($type)) {
                    $was_possibly_undefined = $do_context->vars_in_scope[$var]->possibly_undefined_from_try;
                    $do_context->vars_in_scope[$var] = Type::combineUnionTypes($do_context->vars_in_scope[$var], $type);
                    $do_context->vars_in_scope[$var]->possibly_undefined_from_try = $was_possibly_undefined;
                }
            }
        }

        $mixed_var_ids = [];

        foreach ($do_context->vars_in_scope as $var_id => $type) {
            if ($type->hasMixed()) {
                $mixed_var_ids[] = $var_id;
            }
        }

        $while_clauses = Algebra::getFormula(
            $stmt->cond,
            $context->self,
            $statements_analyzer,
            $codebase
        );

        $while_clauses = array_values(
            array_filter(
                $while_clauses,
                /** @return bool */
                function (Clause $c) use ($mixed_var_ids) {
                    $keys = array_keys($c->possibilities);

                    $mixed_var_ids = \array_diff($mixed_var_ids, $keys);

                    foreach ($keys as $key) {
                        foreach ($mixed_var_ids as $mixed_var_id) {
                            if (preg_match('/^' . preg_quote($mixed_var_id, '/') . '(\[|-)/', $key)) {
                                return false;
                            }
                        }
                    }

                    return true;
                }
            )
        );

        if (!$while_clauses) {
            $while_clauses = [new Clause([], true)];
        }

        $reconcilable_while_types = \Psalm\Type\Algebra::getTruthsFromFormula($while_clauses);

        if ($reconcilable_while_types) {
            $changed_var_ids = [];
            $while_vars_in_scope_reconciled =
                Type\Reconciler::reconcileKeyedTypes(
                    $reconcilable_while_types,
                    $do_context->vars_in_scope,
                    $changed_var_ids,
                    [],
                    $statements_analyzer,
                    [],
                    true,
                    new \Psalm\CodeLocation($statements_analyzer->getSource(), $stmt->cond)
                );

            $do_context->vars_in_scope = $while_vars_in_scope_reconciled;
        }

        foreach ($do_context->vars_in_scope as $var_id => $type) {
            if (isset($context->vars_in_scope[$var_id])) {
                $was_possibly_undefined = $type->possibly_undefined_from_try;
                $context->vars_in_scope[$var_id] = Type::combineUnionTypes($context->vars_in_scope[$var_id], $type);
                $context->vars_in_scope[$var_id]->possibly_undefined_from_try = $was_possibly_undefined;
            }
        }

        LoopAnalyzer::analyze(
            $statements_analyzer,
            $stmt->stmts,
            [$stmt->cond],
            [],
            $loop_scope,
            $inner_loop_context,
            true
        );

        // because it's a do {} while, inner loop vars belong to the main context
        if (!$inner_loop_context) {
            throw new \UnexpectedValueException('Should never be null');
        }

        $negated_while_clauses = Algebra::negateFormula($while_clauses);

        $negated_while_types = Algebra::getTruthsFromFormula(
            Algebra::simplifyCNF(
                array_merge($context->clauses, $negated_while_clauses)
            )
        );

        $inner_loop_context->inside_conditional = true;
        ExpressionAnalyzer::analyze($statements_analyzer, $stmt->cond, $inner_loop_context);
        $inner_loop_context->inside_conditional = false;

        if ($negated_while_types) {
            $changed_var_ids = [];

            $inner_loop_context->vars_in_scope =
                Type\Reconciler::reconcileKeyedTypes(
                    $negated_while_types,
                    $inner_loop_context->vars_in_scope,
                    $changed_var_ids,
                    [],
                    $statements_analyzer,
                    [],
                    true,
                    new \Psalm\CodeLocation($statements_analyzer->getSource(), $stmt->cond)
                );
        }

        foreach ($inner_loop_context->vars_in_scope as $var_id => $type) {
            // if there are break statements in the loop it's not certain
            // that the loop has finished executing, so the assertions at the end
            // the loop in the while conditional may not hold
            if (in_array(ScopeAnalyzer::ACTION_BREAK, $loop_scope->final_actions, true)) {
                if (isset($loop_scope->possibly_defined_loop_parent_vars[$var_id])) {
                    $context->vars_in_scope[$var_id] = Type::combineUnionTypes(
                        $type,
                        $loop_scope->possibly_defined_loop_parent_vars[$var_id]
                    );
                }
            } else {
                $context->vars_in_scope[$var_id] = $type;
            }
        }

        $do_context->loop_scope = null;

        $context->vars_possibly_in_scope = array_merge(
            $context->vars_possibly_in_scope,
            $do_context->vars_possibly_in_scope
        );

        $context->referenced_var_ids = array_merge(
            $context->referenced_var_ids,
            $do_context->referenced_var_ids
        );

        if ($context->collect_references) {
            $context->unreferenced_vars = $inner_loop_context->unreferenced_vars;
        }

        if ($context->collect_exceptions) {
            $context->mergeExceptions($inner_loop_context);
        }
    }
}
