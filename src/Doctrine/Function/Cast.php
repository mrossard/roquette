<?php

declare(strict_types=1);

namespace App\Doctrine\Function;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * CAST(expression AS type)
 */
class Cast extends FunctionNode
{
    private Node $expression;
    private string $type;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf('CAST(%s AS %s)', $this->expression->dispatch($sqlWalker), $this->type);
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->expression = $parser->SimpleArithmeticExpression();

        $parser->match(TokenType::T_AS);
        $parser->match(TokenType::T_IDENTIFIER);

        $this->type = $parser->getLexer()->token->value;

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
