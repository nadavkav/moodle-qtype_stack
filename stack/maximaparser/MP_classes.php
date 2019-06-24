<?php
// This file is part of Stateful.
//
// Stateful is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Stateful is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Stateful.  If not, see <http://www.gnu.org/licenses/>.
// Stateful by Matti Harjula 2017.

/*
 * Class defintions for the PHP version of the PEGJS parser.
 * toString functions are mainly to document what the objects parts mean. But
 * you can do some debugging with them.
 * end of the file contains functions the parser uses...
 *
 * function to_String should return something which is completely correct in Maxima.
 * Known parameter values for toString.
 *
 * 'pretty'                  Used for debug pretty-printing of the statement.
 * 'insertstars_as_red'      All * operators created by insert stars logic will be marked with red.
 * 'fixspaces_as_red_spaces' Similar to above, but for spaces.
 * 'inputform'               Something a user (normally student) would expect to type.
 * 'nounify'                 If defined and true nounifies certain operators and functions. If false does the opposite.
 * 'dealias'                 If defined unpacks potenttial aliases.
 * 'qmchar'                  If defined prints question marks directly if present as QMCHAR
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../cas/cassecurity.class.php');

class MP_Node {
    public $parentnode  = null;
    public $position    = null;

    public function __construct() {
        $this->parentnode = null;
        $this->position   = [];
    }

    public function getChildren() {
        return [];
    }

    public function hasChildren() {
        return count($this->getChildren()) > 0;
    }

    public function toString($params = null): string {
        return '[NO TOSTRING FOR ' . get_class($this) . ']';
    }

    // Calls a function for all this nodes children.
    // Callback needs to take a node and return true if it changes nothing or does no structural changes.
    // If it does structural changes it must return false so that the recursion may be repeated on
    // the changed structure.
    // Calling with null function will upgrade parentnodes, but does nothing else.
    // Which may be necessary in some cases, where modifications are heavy and the paintting
    // cannot paint fast enough, should you parentnode happen to be null then this might
    // have happened we do not do this automatically as most code works without back referencing.
    // One may also declare that invalid subtrees are not to be processed.
    public function callbackRecurse($function = null, $skipinvalid = false) {
        if ($skipinvalid === true && isset($this->position['invalid']) &&
            $this->position['invalid'] === true) {
            return true;
        }
        foreach ($this->getChildren() as $child) {
            // Not a foreach as the list may change.
            $child->parentnode = $this;
            if (!($skipinvalid === true && isset($child->position['invalid'])
                && $child->position['invalid'] === true) &&
                $function !== null && $function($child) !== true) {
                return false;
            }
            if ($child->callbackRecurse($function, $skipinvalid) !== true) {
                return false;
            }
        }
        return true;
    }

    public function asAList() {
        // This one recursively goes through the whole tree and returns a list of
        // all the nodes found, it also populates the parent details as those might
        // be handy. You can act more efficiently with that list if you need to go
        // through it multiple times than if you were to recurse the tree multiple
        // times. Especially, when the tree is deep.
        $r = [$this];

        foreach ($this->getChildren() as $child) {
            $child->parentnode = $this;
            $r                 = array_merge($r, $child->asAList());
        }
        return $r;
    }

    // Replace a child of this now with other...
    public function replace($node, $with) {
        // Noop for most.
    }

    public function debugPrint($originalcode) {
        $r = [$originalcode];
        if (!is_array($this->position) || !isset($this->position['start']) || !
            is_int($this->position['start'])) {
            return 'Not possible to debug print without position data for the root node.';
        }
        $ofset = $this->position['start'];
        foreach ($this->asAList() as $node) {
            $i = $node;

            while (!is_array($i->position) || !isset($i->position['start'])) {
                $i = $i->parentnode;
            }
            $line = str_pad('', $i->position['start'] - $ofset);
            if ($i === $node) {
                $line .= str_pad('', $i->position['end'] - $i->position['start'
                ], '-');
            } else {
                $line .= str_pad('', $i->position['end'] - $i->position['start'
                ], '?');
            }
            $line = str_pad($line, strlen($originalcode) + 1);
            if (is_a($node, 'MP_EvaluationFlag')) {
                $line .= get_class($node);
            } if (is_a($node, 'MP_Float') || is_a($node, 'MP_Integer')) {
                if ($node->raw !== null) {
                    $line .= get_class($node) . ' ' . $node->raw;
                } else {
                    $line .= get_class($node) . ' ' . $node->value;
                }
            } else {
                $line .= get_class($node) . ' ' . @$node->op . @$node->value .
                @$node->mode;
            }
            if (is_a($node, 'MP_Operation') && $node->op === '*') {
                if (isset($node->position['fixspaces'])) {
                    $line .= ' [fixspaces]';
                }
                if (isset($node->position['insertstars'])) {
                    $line .= ' [insertstars]';
                }
            }
            if ($node->is_invalid()) {
                $line .= '!';
            }
            $r[] = rtrim($line);
        }
        return implode("\n", $r);
    }

    // Re calculates the positions of nodes from their contents not from
    // the original parsed content. Uses minimal toString() presentation.
    // Intended to ease interpretation of debug prints in some cases.
    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        // For recursion this needs more. But this works for the general case.
    }


    public function is_invalid(): bool {
        if (isset($this->position['invalid']) && $this->position['invalid'] === true) {
            return true;
        }
        if ($this->parentnode !== null) {
            return $this->parentnode->is_invalid();
        }
        return false;
    }


    // Quick check if we are part of an operation.
    public function is_in_operation() {
        if ($this->parentnode === null) {
            return false;
        }
        if (is_a($this->parentnode, 'MP_Operation')) {
            return true;
        }
        return is_a($this->parentnode, 'MP_PrefixOp') || is_a($this->parentnode
            , 'MP_PostfixOp');
    }

    // Extraction of terms in operations without caring about the references.
    // Returns null if none present or we are not part of an operation.
    /* TODO: bugs with '-a*b^c-d+e!+f/g(x+y)+z' for d.
    public function get_operand_on_right() {
    if ($this->parentnode === null) {
    return null;
    }

    if (is_a($this->parentnode, 'MP_Operation') && $this->parentnode->lhs === $this) {
    return $this->parentnode->leftmostofright();
    }

    if (is_a($this->parentnode, 'MP_Operation') || is_a($this->parentnode, 'MP_PrefixOp') ||
        is_a($this->parentnode, 'MP_PostfixOp')) {
    return $this->parentnode->get_operand_on_right();
    }

    return null;
    }
     */

    public function get_operand_on_left() {
        // This will not unpack postfix terms so 5!+this => '5!'
        if ($this->parentnode === null) {
            return null;
        }

        if (is_a($this->parentnode, 'MP_Operation') && $this->parentnode->rhs
            === $this) {
            return $this->parentnode->rightmostofleft();
        }

        if (is_a($this->parentnode, 'MP_Operation') || is_a($this->parentnode,
            'MP_PrefixOp') || is_a($this->parentnode, 'MP_PostfixOp')) {
            return $this->parentnode->get_operand_on_left();
        }

        return null;
    }

    public function get_operator_on_left() {
        // This will not unpack postfix terms so 5!+this => '+'.
        if ($this->parentnode === null) {
            return null;
        }
        if (is_a($this->parentnode, 'MP_PrefixOp')) {
            return $this->parentnode->op;
        }
        if (is_a($this->parentnode, 'MP_Operation') && $this->parentnode->rhs
            === $this) {
            return $this->parentnode->op;
        }
        if (is_a($this->parentnode, 'MP_Operation') || is_a($this->parentnode,
            'MP_PrefixOp') || is_a($this->parentnode, 'MP_PostfixOp')) {
            return $this->parentnode->get_operator_on_left();
        }
        return null;
    }

    /* TODO: bugs with '-a*b^c-d+e!+f/g(x+y)+z' for d.
    public function get_operator_on_right() {
        if ($this->parentnode === null) {
            return null;
        }
        if (is_a($this->parentnode, 'MP_PostfixOp')) {
            return $this->parentnode->op;
        }
        if (is_a($this->parentnode, 'MP_Operation') && $this->parentnode->lhs === $this) {
            return $this->parentnode->op;
        }
        if (is_a($this->parentnode, 'MP_Operation') || is_a($this->parentnode, 'MP_PrefixOp') ||
            is_a($this->parentnode, 'MP_PostfixOp')) {
            return $this->parentnode->get_operator_on_right();
        }
        return null;
    }
    */
}

class MP_Operation extends MP_Node {
    public $op  = '+';
    public $rhs = null;
    public $lhs = null;

    public function __construct($op, $lhs, $rhs) {
        parent::__construct();
        $this->op         = $op;
        $this->lhs        = $lhs;
        $this->rhs        = $rhs;
    }

    public function __clone() {
        $this->rhs = clone $this->rhs;
        $this->lhs = clone $this->lhs;
        $this->rhs->parentnode = $this;
        $this->lhs->parentnode = $this;
    }

    public function getChildren() {
        return [$this->lhs, $this->rhs];
    }

    public function toString($params = null): string {
        $op = $this->op;
        if ($params !== null && isset($params['nounify'])) {
            $feat = null;
            if ($params['nounify'] === true) {
                $feat = stack_cas_security::get_feature($op, 'nounoperator');
            }
            if ($params['nounify'] === false) {
                $feat = stack_cas_security::get_feature($op, 'nounoperatorfor');
            }
            if ($feat !== null) {
                $op = $feat;
            }
        }

        if ($params !== null && isset($params['pretty'])) {
            $indent = '';
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
            $params['pretty'] = 0;
            return $indent . $this->lhs->toString($params) . ' ' . $op .
            ' ' . $this->rhs->toString($params);
        }
        if ($params !== null && isset($params['insertstars_as_red']) && $op === '*'
                && isset($this->position['insertstars'])) {
            // This is a special rendering rule that colors all multiplications as red if they have no position.
            // i.e. if they have been added after parsing...
            return $this->lhs->toString($params) . '<font color="red">' . $op . '</font>' .
                $this->rhs->toString($params);
        }
        if ($params !== null && isset($params['fixspaces_as_red_spaces']) &&
            $this->op === '*' && isset($this->position['fixspaces'])) {
            // This is a special rendering rule that colors all multiplications as red if they have no position.
            // i.e. if they have been added after parsing...
            return $this->lhs->toString($params) . '<font color="red">_</font>'
            . $this->rhs->toString($params);
        }
        if (stack_cas_security::get_feature($op, 'spacesurroundedop') !== null) {
            return $this->lhs->toString($params) . ' ' . $op . ' ' . $this->rhs->toString($params);
        }
        return $this->lhs->toString($params) . $op . $this->rhs->toString($params);
    }

    public function remap_position_data(int $offset=0) {
        $lhs = $this->lhs->toString();
        $rhs = $this->rhs->toString();
        $start = $offset;
        $op = $this->op;
        if (stack_cas_security::get_feature($op, 'spacesurroundedop') !== null) {
            $op = ' ' . $op . '';
        }
        $this->position['start'] = $start;
        $this->position['end'] = $start + core_text::strlen($lhs) + core_text::strlen($op) + core_text::strlen($rhs);
        $this->lhs->remap_position_data($start);
        $this->rhs->remap_position_data($start + core_text::strlen($lhs) + core_text::strlen($op));
    }

    // Replace a child of this now with other...
    public function replace($node, $with) {
        if ($this->lhs === $node) {
            $this->lhs = $with;
        } else if ($this->rhs === $node) {
            $this->rhs = $with;
        }
    }

    // Goes up the tree to identify if there is any op on the right of this.
    public function operationOnRight() {
        if ($this->parentnode === null || !($this->parentnode instanceof
            MP_Operation || $this->parentnode instanceof MP_PostfixOp)) {
            return null;
        }
        if ($this->parentnode->lhs === $this) {
            return $this->parentnode->op;
        } else {
            return $this->parentnode->operationOnRight();
        }
    }

    public function operationOnLeft() {
        if ($this->parentnode === null || !($this->parentnode instanceof
            MP_Operation || $this->parentnode instanceof MP_PrefixOp)) {
            return null;
        }
        if ($this->parentnode->rhs === $this) {
            return $this->parentnode->op;
        } else {
            return $this->parentnode->operationOnLeft();
        }
    }

    // Goes up the tree and back again to find the operand next to this operation.
    public function operandOnRight() {
        if ($this->parentnode === null || !($this->parentnode instanceof
            MP_Operation || $this->parentnode instanceof MP_PostfixOp)) {
            return null;
        }
        $i    = $this->parentnode;
        $last = $this;

        while ($i->lhs === $last) {
            $last = $i;
            $i    = $i->parentnode;
            if ($i === null || ($i instanceof MP_Operation || $i instanceof
                MP_PostfixOp)) {
                return null;
            }
        }
        // Pointer $i is now the top of the branch and we go down the rhs sides left edge.
        return $i->leftmostofright();
    }

    public function operandOnLeft() {
        if ($this->parentnode === null || !($this->parentnode instanceof
            MP_Operation || $this->parentnode instanceof MP_PrefixOp)) {
            return null;
        }
        $i    = $this->parentnode;
        $last = $this;

        while ($i->rhs === $last) {
            $last = $i;
            $i    = $i->parentnode;
            if ($i === null || ($i instanceof MP_Operation || $i instanceof
                MP_PostfixOp)) {
                return null;
            }
        }
        // Pointer $i is now the top of the branch and we go down the lhs sides right edge.
        return $i->rightmostofleft();
    }

    public function leftmostofright() {
        $i = $this->rhs;

        while ($i instanceof MP_Operation || $i instanceof MP_PostfixOp) {
            $i = $i->lhs;
        }
        return $i;
    }

    public function rightmostofleft() {
        $i = $this->lhs;

        while ($i instanceof MP_Operation || $i instanceof MP_PrefixOp) {
            $i = $i->rhs;
        }
        return $i;
    }
}

class MP_Atom extends MP_Node {
    public $value = null;

    public function __construct($value) {
        parent::__construct();
        $this->value = $value;
    }

    public function remap_position_data(int $offset=0) {
        $value = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($value);
    }

    public function toString($params = null): string {
        if ($params !== null && isset($params['pretty'])) {
            $indent = '';
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
            return $indent . $this->value;
        }
        return '' . $this->value;
    }
}

class MP_Integer extends MP_Atom {
                     // In certain cases we want to see the original form of the integer.
                     // Typically when that value is much longer than what we can deal with.
                     // In the future the integer class might also describe other than base-10
                     // values in which case the representation has more use.
    public $raw = null;

    public function __construct(
        $value,
        $raw = null
    ) {
        parent::__construct($value);
        $this->raw = $raw;
    }

    public function toString($params = null): string {
        if ($params !== null && isset($params['pretty'])) {
            $indent = '';
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
            if ($this->raw !== null) {
                return $indent . $this->raw;
            }

            return $indent . $this->value;
        }

        if ($this->raw !== null) {
            return $this->raw;
        }

        return '' . $this->value;
    }
}

class MP_Float extends MP_Atom {
    // In certain cases we want to see the original form of the float.
    public $raw = null;

    public function __construct($value, $raw) {
        parent::__construct($value);
        $this->raw = $raw;
    }

    public function toString($params = null): string {
        // For normalisation purposes we will always uppercase the e.
        if ($params !== null && isset($params['pretty'])) {
            $indent = '';
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
            if ($this->raw !== null) {
                return $indent . strtoupper($this->raw);
            }

            return $indent . strtoupper('' . $this->value);
        }

        if ($this->raw !== null) {
            return strtoupper($this->raw);
        }

        return strtoupper('' . $this->value);
    }
}

class MP_String extends MP_Atom {

    public function toString($params = null): string {
        if ($params !== null && isset($params['pretty'])) {
            $indent = '';
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
            return $indent . '"' . str_replace('"', '\\"', str_replace('\\', '\\\\', $this->value)) . '"';
        }

        return '"' . str_replace('"', '\\"', str_replace('\\', '\\\\', $this->value)) . '"';
    }
}

class MP_Boolean extends MP_Atom {

    public function toString($params = null): string {
        if ($params !== null && isset($params['pretty'])) {
            $indent = '';
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
            return $indent . ($this->value ? 'true' : 'false');
        }

        return $this->value ? 'true' : 'false';
    }
}

class MP_Identifier extends MP_Atom {
    // Convenience functions that work only after $parentnode has been filled in.
    public function is_function_name(): bool {
        // Note that the first argument of map-functions is a function name.
        if ($this->parentnode != null && $this->parentnode instanceof MP_FunctionCall) {
            if ($this->parentnode->name === $this) {
                return true;
            }
            if ($this->parentnode->arguments != null &&
                $this->parentnode->arguments[0] === $this &&
                stack_cas_security::get_feature($this->parentnode->name->toString(), 
                    'mapfunction') === true) {
                return true;
            }
        }
        return false;
    }

    public function is_variable_name(): bool {
        return !$this->is_function_name();
    }

    public function toString($params = null): string {
        $indent = '';
        if ($params !== null && isset($params['pretty'])) {
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
        }

        if ($params !== null && isset($params['qmchar'])) {
            return $indent . str_replace('QMCHAR', '?', $this->value);
        }

        return $indent . $this->value;
    }

    public function is_being_written_to(): bool {
        if ($this->is_function_name()) {
            return $this->parentnode->is_definition();
        } else {
            // Direct assignment.
            if ($this->parentnode != null && $this->parentnode instanceof MP_Operation
                    && $this->parentnode->op === ':' && $this->parentnode->lhs === $this) {
                return true;
            } else if ($this->parentnode != null && $this->parentnode instanceof MP_List) {
                // Multi assignment.
                if ($this->parentnode->parentnode != null &&
                        $this->parentnode->parentnode instanceof MP_Operation &&
                        $this->parentnode->parentnode->lhs === $this->parentnode) {
                    return $this->parentnode->parentnode->op === ':';
                }
            } else if ($this->parentnode != null && 
                       $this->parentnode instanceof MP_FunctionCall &&
                       $this->parentnode->name !== $this) {
                // Assignment by reference.
                $i = array_search($this, $this->parentnode->arguments);
                $indices = stack_cas_security::get_feature($this->parentnode->name->toString(), 
                    'writesto');
                if ($indices !== null && array_search($i, $indices) !== false) {
                    return true;
                }
            }
            return false;
        }
    }

    public function is_global(): bool {
        // This is expensive as we need to travel the whole parent-chain and do some paraller checks.
        $i = $this->parentnode;

        while ($i != null) {
            if ($i instanceof MP_FunctionCall) {
                if ($i->is_definition()) {
                    return false;
                    // The arguments of a function definition are scoped to that function.
                } else if ($i->name->value === 'block' || $i->name->value ===
                    'lambda') {
                    // If this is wrapped to a block/lambda then we check the first arguments contents.
                    if ($i->arguments[0] instanceof MP_List) {

                        foreach ($i->arguments[0]->getChildren() as $v) {
                            if ($v instanceof MP_Identifier && $v->value === $this->value) {
                                return false;
                            }
                        }
                    }
                }
            } else if ($i instanceof MP_Operation && ($i->op === ':=' || $i->op
                === '::=')) {
                // The case where we exist on the rhs of function definition.
                if ($i->lhs instanceof MP_FunctionCall) {

                    foreach ($i->lhs->arguments as $v) {
                        if ($v instanceof MP_Identifier && $v->value === $this->value) {
                            return false;
                        }
                    }
                }
            }
            $i = $i->parentnode;
        }
        return true;
    }
}

class MP_Annotation extends MP_Node {
    public $annotationType = null;
    public $params         = null;

    public function __constructor($annotationType, $params) {
        parent::__construct();
        $this->annotationType = $annotationType;
        $this->params         = $params;
    }

    public function __clone() {
        if ($this->params !== null && count($this->params) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->params); $i++) {
                $this->params[$i] = clone $this->params[$i];
                $this->params[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return $this->params;
    }
    public function toString($params = null): string {
        $params = [];

        foreach ($this->params as $value) {
            $params[] = ' ' . $value->toString($params);
        }
        if ($this->annotationType === 'function') {
            return '@function' . $params[0] . ' =>' . $params[1] . ';';
        }
        return '@' . $this->annotationType . implode('', $params) . ';';
    }
}

class MP_Comment extends MP_Node {
    public $value       = null;
    public $annotations = null;

    public function __construct($value, $annotations) {
        parent::__construct();
        $this->value       = $value;
        $this->annotations = $annotations;
    }

    public function __clone() {
        if ($this->annotations !== null && count($this->annotations) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->annotations); $i++) {
                $this->annotations[$i] = clone $this->annotations[$i];
                $this->annotations[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return $this->annotations;
    }

    public function toString($params = null): string {
        $annotations = [];

        foreach ($this->annotations as $value) {
            $annotations[] = $value->toString($params);
        }

        if ($params !== null && isset($params['pretty'])) {
            return "\n/*" . $this->value . implode("\n", $annotations) . "*/\n";
        }

        return '/*' . $this->value . implode("\n", $annotations) . '*/';
    }
}

class MP_FunctionCall extends MP_Node {
    public $name      = null;
    public $arguments = null;

    public function __construct($name, $arguments) {
        parent::__construct();
        $this->name      = $name;
        $this->arguments = $arguments;
    }

    public function __clone() {
        $this->name = clone $this->name;
        $this->name->parentnode = $this;
        if ($this->arguments !== null && count($this->arguments) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->arguments); $i++) {
                $this->arguments[$i] = clone $this->arguments[$i];
                $this->arguments[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return array_merge([$this->name], $this->arguments);
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $itemoffset = $offset + core_text::strlen($this->name->toString()) + 1;
        foreach ($this->arguments as $arg) {
            $arg->remap_position_data($itemoffset);
            $itemoffset = $itemoffset + core_text::strlen($arg->toString()) + 1;
        }
        $this->name->remap_position_data($offset);
    }

    public function toString($params = null): string {
        $n = $this->name->toString($params);
        if ($params !== null && isset($params['nounify'])) {
            if ($this->name instanceof MP_Identifier || $this->name instanceof MP_String) {
                $feat = stack_cas_security::get_feature($this->name->value, 'nounfunction');
                if ($params['nounify'] === false) {
                    $feat = stack_cas_security::get_feature($this->name->value, 'nounfunctionfor');
                }
                if ($feat !== null) {
                    $n = $feat;
                }
            }
        }

        if ($params !== null && isset($params['pretty'])) {
            $indent = '';
            if (!$this->name instanceof MP_Identifier && !$this->name instanceof MP_String) {
                $n = $this->name->toString();
            }

            if (is_integer($params['pretty'])) {
                $indent           = str_pad($indent, $params['pretty']);
                $params['pretty'] = $params['pretty'] + 2;
            } else {
                $params['pretty'] = 2;
            }
            if ($n === 'block' || $n === 'matrix') {
                $r  = $indent . $n . "(\n$indent";
                $ar = [];
                foreach ($this->arguments as $value) {
                    $ar[] = $value->toString($params);
                }

                $r .= implode(",\n", $ar);
                $r .= "\n" . $indent . ')';
                return $r;
            } else {
                $r                = $indent . ltrim($this->name->toString($params)) .
                    '(';
                $ar = [];

                foreach ($this->arguments as $value) {
                    $ar[] = ltrim($value->toString($params));
                }

                return $r . implode(', ', $ar) . ')';
            }
        }

        $ar = [];
        foreach ($this->arguments as $value) {
            $ar[] = $value->toString($params);
        }

        if (isset($params['inputform']) && $params['inputform'] === true) {
            $prefix = stack_cas_security::get_feature($this->name->value, 'prefixinputform');
            if ('' != $prefix) {
                // Hack for stacklet.
                if ($n == 'stacklet') {
                    // TODO: fix parsing of let
                    // return $prefix .' '. implode('=', $ar);
                }
                return $prefix .' '. implode(',', $ar);
            }
        }

        return $n . '(' . implode(',', $ar) . ')';
    }
    // Covenience functions that work only after $parentnode has been filled in.
    public function is_definition(): bool {
        return $this->parentnode != null && $this->parentnode instanceof
        MP_Operation && ($this->parentnode->op === ':=' || $this->parentnode->op === '::=') &&
            $this->parentnode->lhs === $this;
    }

    public function is_call(): bool {
        return !$this->is_definition();
    }

    public function replace($node, $with) {
        if ($this->name === $node) {
            $this->name = $with;
        } else if ($node === -1) {
            // Special case. append a node to arguments.
            $this->arguments[] = $with;
        } else {

            foreach ($this->arguments as $key => $value) {
                if ($value === $node) {
                    $this->arguments[$key] = $with;
                }
            }
        }
    }
}

class MP_Group extends MP_Node {
    public $items = null;

    public function __construct($items) {
        parent::__construct();
        $this->items    = $items;
    }

    public function __clone() {
        if ($this->items !== null && count($this->items) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->items); $i++) {
                $this->items[$i] = clone $this->items[$i];
                $this->items[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return $this->items;
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $itemoffset = $offset + 1;
        foreach ($this->items as $item) {
            $item->remap_position_data($itemoffset);
            $itemoffset = $itemoffset + core_text::strlen($item->toString()) + 1;
        }
    }

    public function toString($params = null): string {
        $indent = '';
        if ($params !== null && isset($params['pretty'])) {
            if (is_integer($params['pretty'])) {
                $indent           = str_pad($indent, $params['pretty']);
                $params['pretty'] = $params['pretty'] + 2;
            } else {
                $params['pretty'] = 2;
            }
        }

        $ar = [];

        foreach ($this->items as $value) {
            $ar[] = $value->toString($params);
        }

        if ($params !== null && isset($params['pretty'])) {
            $t = strlen($this->toString()) + count($this->items);

            if ($t > 20) {
                return $indent . "(\n" . implode(", \n", $ar) . "\n$indent)";
            }
            $params['pretty'] = 0;
            $ar               = [];

            foreach ($this->items as $value) {
                $ar[] = $value->toString($params);
            }

            return $indent . '(' . implode(', ', $ar) . ')';
        }

        return '(' . implode(',', $ar) . ')';
    }

    public function replace($node, $with) {
        if ($node === -1) {
            // Special case. Append a node to items.
            $this->items[] = $with;
        } else {

            foreach ($this->items as $key => $value) {
                if ($value === $node) {
                    $this->items[$key] = $with;
                }
            }
        }
    }
}

class MP_Set extends MP_Node {
    public $items = null;

    public function __construct($items) {
        parent::__construct();
        $this->items    = $items;
    }

    public function __clone() {
        if ($this->items !== null && count($this->items) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->items); $i++) {
                $this->items[$i] = clone $this->items[$i];
                $this->items[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return $this->items;
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $itemoffset = $offset + 1;
        foreach ($this->items as $item) {
            $item->remap_position_data($itemoffset);
            $itemoffset = $itemoffset + core_text::strlen($item->toString()) + 1;
        }
    }

    public function toString($params = null): string {
        $indent = '';
        if ($params !== null && isset($params['pretty'])) {
            if (is_integer($params['pretty'])) {
                $indent           = str_pad($indent, $params['pretty']);
                $params['pretty'] = $params['pretty'] + 2;
            } else {
                $params['pretty'] = 2;
            }
        }

        $ar = [];

        foreach ($this->items as $value) {
            $ar[] = $value->toString($params);
        }

        if ($params !== null && isset($params['pretty'])) {
            $t = strlen($this->toString()) + count($this->items);

            if ($t > 20) {
                return $indent . "{\n" . implode(", \n", $ar) . "\n$indent}";
            }
            $params['pretty'] = 0;
            $ar               = [];

            foreach ($this->items as $value) {
                $ar[] = $value->toString($params);
            }

            return $indent . '{' . implode(', ', $ar) . '}';
        }

        return '{' . implode(',', $ar) . '}';
    }

    public function replace($node, $with) {
        if ($node === -1) {
            // Special case. append a node to items.
            $this->items[] = $with;
        } else {

            foreach ($this->items as $key => $value) {
                if ($value === $node) {
                    $this->items[$key] = $with;
                }
            }
        }
    }
}

class MP_List extends MP_Node {
    public $items = null;

    public function __construct($items) {
        parent::__construct();
        $this->items    = $items;
    }

    public function __clone() {
        if ($this->items !== null && count($this->items) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->items); $i++) {
                $this->items[$i] = clone $this->items[$i];
                $this->items[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return $this->items;
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $itemoffset = $offset + 1;
        foreach ($this->items as $item) {
            $item->remap_position_data($itemoffset);
            $itemoffset = $itemoffset + core_text::strlen($item->toString()) + 1;
        }
    }

    public function toString($params = null): string {
        $indent = '';
        if ($params !== null && isset($params['pretty'])) {
            if (is_integer($params['pretty'])) {
                $indent           = str_pad($indent, $params['pretty']);
                $params['pretty'] = $params['pretty'] + 2;
            } else {
                $params['pretty'] = 2;
            }
        }

        $ar = [];

        foreach ($this->items as $value) {
            $ar[] = $value->toString($params);
        }

        if ($params !== null && isset($params['pretty'])) {
            $t = strlen($this->toString()) + count($this->items);

            if ($t > 20) {
                return $indent . "[\n" . implode(", \n", $ar) . "\n$indent]";
            }
            $params['pretty'] = 0;
            $ar               = [];

            foreach ($this->items as $value) {
                $ar[] = $value->toString($params);
            }

            return $indent . '[' . implode(', ', $ar) . ']';
        }

        return '[' . implode(',', $ar) . ']';
    }

    public function replace($node, $with) {
        if ($node === -1) {
            // Special case. Append a node to items.
            $this->items[] = $with;
        } else {

            foreach ($this->items as $key => $value) {
                if ($value === $node) {
                    $this->items[$key] = $with;
                }
            }
        }
    }
}

class MP_PrefixOp extends MP_Node {
    public $op  = '-';
    public $rhs = null;

    public function __construct($op, $rhs) {
        parent::__construct();
        $this->op         = $op;
        $this->rhs        = $rhs;
    }

    public function __clone() {
        $this->rhs = clone $this->rhs;
        $this->rhs->parent = $this;
    }

    public function getChildren() {
        return [$this->rhs];
    }


    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $this->rhs->remap_position_data($offset + core_text::strlen($this->op));
    }

    public function toString($params = null): string {
        $indent = '';
        if ($params !== null && isset($params['pretty'])) {
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
            $params['pretty'] = 0;
            if ($this->op === 'not') {
                return $indent . $this->op . ' ' . $this->rhs->toString($params
                );
            }
            return $indent . $this->op . $this->rhs->toString($params);
        }

        if ($this->op === 'not') {
            return $this->op . ' ' . $this->rhs->toString($params);
        }
        return $this->op . $this->rhs->toString($params);
    }

    public function replace($node, $with) {
        if ($this->rhs === $node) {
            $this->rhs      = $with;
        }
    }
}

class MP_PostfixOp extends MP_Node {
    public $op  = '!';
    public $lhs = null;

    public function __construct($op, $lhs) {
        parent::__construct();
        $this->op         = $op;
        $this->lhs        = $lhs;
    }

    public function __clone() {
        $this->lhs = clone $this->lhs;
        $this->lhs->parent = $this;
    }

    public function getChildren() {
        return [$this->lhs];
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $this->lhs->remap_position_data($offset);
    }


    public function toString($params = null): string {
        $indent = '';
        if ($params !== null && isset($params['pretty'])) {
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
            }
            $params['pretty'] = 0;
            return $indent . $this->lhs->toString($params) . $this->op;
        }

        return $this->lhs->toString($params) . $this->op;
    }

    public function replace($node, $with) {
        if ($this->lhs === $node) {
            $this->lhs      = $with;
        }
    }
}

class MP_Indexing extends MP_Node {
    public $target = null;
    // This is and identifier or a function call.
    public $indices = null;
    // These are MP_List objects.
    public function __construct($target, $indices) {
        parent::__construct();
        $this->target   = $target;
        $this->indices  = $indices;
    }

    public function __clone() {
        $this->target = clone $this->target;
        $this->target->parentnode = $this;
        if ($this->indices !== null && count($this->indices) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->indices); $i++) {
                $this->indices[$i] = clone $this->indices[$i];
                $this->indices[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return array_merge([$this->target], $this->indices);
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $this->target->remap_position_data($offset);
        $itemoffset = $offset + core_text::strlen($this->target->toString());
        foreach ($this->indices as $ind) {
            $ind->remap_position_data($itemoffset);
            $itemoffset = $itemoffset + core_text::strlen($ind->toString());
        }
    }

    public function toString($params = null): string {
        $r = $this->target->toString($params);

        foreach ($this->indices as $ind) {
            $r .= $ind->toString($params);
        }

        return $r;
    }

    public function replace($node, $with) {
        if ($this->target === $node) {
            $this->target = $with;
        } else {
            foreach ($this->indices as $key => $value) {
                if ($value === $node) {
                    $this->indices[$key] = $with;
                }
            }
        }
    }
}

class MP_If extends MP_Node {
    public $conditions = null;
    public $branches   = null;

    public function __construct($conditions, $branches) {
        parent::__construct();
        $this->conditions = $conditions;
        $this->branches   = $branches;
    }


    public function __clone() {
        if ($this->conditions !== null && count($this->conditions) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->conditions); $i++) {
                $this->conditions[$i] = clone $this->conditions[$i];
                $this->conditions[$i]->parentnode = $this;
            }
        }
        if ($this->branches !== null && count($this->branches) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->branches); $i++) {
                $this->branches[$i] = clone $this->branches[$i];
                $this->branches[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return array_merge($this->conditions, $this->branches);
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        // TODO: fill in this.
    }

    public function toString($params = null): string {
        $indent = '';
        if ($params !== null && isset($params['pretty'])) {
            $ind = 2;
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
                $ind    = $params['pretty'] + 2;
            }
            $params['pretty'] = 0;

            $r = $indent . 'if ' . $this->conditions[0]->toString($params) . " then\n";
            $params['pretty'] = $ind;
            $r .= $this->branches[0]->toString($params);
            if (count($this->conditions) > 1) {
                for ($i = 1;
                    $i < count($this->conditions);
                    $i++) {
                    $params['pretty'] = 0;
                    $r .= "\n$indent" . 'elseif ' . $this->conditions[$i]->toString($params) . " then\n";
                    $params['pretty'] = $ind;
                    $r .= $this->branches[$i]->toString($params);
                }
            }
            if (count($this->branches) > count($this->conditions)) {
                $r .= "\n$indent" . "else\n" . $this->branches[count($this->conditions)]->toString($params);
            }

            return $r;
        }

        $r = 'if ' . $this->conditions[0]->toString($params) . ' then ' . $this->branches[0]->toString($params);
        if (count($this->conditions) > 1) {
            for ($i = 1;
                $i < count($this->conditions);
                $i++) {
                $r .= ' elseif ' . $this->conditions[$i]->toString($params) .
                ' then ' . $this->branches[$i]->toString($params);
            }
        }
        if (count($this->branches) > count($this->conditions)) {
            $r .= ' else ' . $this->branches[count($this->conditions)]->toString($params);
        }

        return $r;
    }

    public function replace($node, $with) {

        foreach ($this->conditions as $key => $value) {
            if ($value === $node) {
                $this->conditions[$key] = $with;
            }
        }

        foreach ($this->branches as $key => $value) {
            if ($value === $node) {
                $this->branches[$key] = $with;
            }
        }
    }
}

class MP_Loop extends MP_Node {
    public $body = null;
    public $conf = null;

    public function __construct($body, $conf) {
        parent::__construct();
        $this->body     = $body;
        $this->conf     = $conf;
    }

    public function __clone() {
        if ($this->conf !== null && count($this->conf) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->conf); $i++) {
                $this->conf[$i] = clone $this->conf[$i];
                $this->conf[$i]->parentnode = $this;
            }
        }
        $this->body = clone $this->body;
        $this->body->parentnode = $this;
    }

    public function getChildren() {
        return array_merge($this->conf, [$this->body]);
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        // TODO: fill in this.
    }

    public function replace($node, $with) {

        foreach ($this->conf as $key => $value) {
            if ($value === $node) {
                $this->conf[$key] = $with;
            }
        }
        if ($this->body === $node) {
            $this->body = $with;
        }
    }

    public function toString($params = null): string {
        $indent = '';
        if ($params !== null && isset($params['pretty'])) {
            $ind = 2;
            if (is_integer($params['pretty'])) {
                $indent = str_pad($indent, $params['pretty']);
                $ind    = $params['pretty'] + 2;
            }
            $params['pretty'] = 0;
        }

        $bits = [];
        foreach ($this->conf as $bit) {
            $bits[] = $bit->toString($params);
        }

        if ($params !== null && isset($params['pretty'])) {
            $params['pretty'] = $ind;
            return $indent . implode(' ', $bits) . "\n" . $indent . "do\n" .
                $this->body->toString($params);
        }

        return implode(' ', $bits) . ' do ' . $this->body->toString($params);
    }
}

class MP_LoopBit extends MP_Node {
    public $mode  = null;
    public $param = null;

    public function __construct($mode, $param) {
        parent::__construct();
        $this->mode       = $mode;
        $this->param      = $param;
    }

    public function __clone() {
        $this->param = clone $this->param;
        $this->param->parentnode = $this;
    }

    public function getChildren() {
        return [$this->param];
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $this->param->remap_position_data($offset + core_text::strlen($this->mode) + 1);
    }


    public function replace(
        $node,
        $with
    ) {
        if ($this->param === $node) {
            $this->param = $with;
        }
    }

    public function toString($params = null): string {
        return $this->mode . ' ' . $this->param->toString($params);
    }
}

class MP_EvaluationFlag extends MP_Node {
    public $name  = null;
    public $value = null;

    public function __construct($name, $value) {
        parent::__construct();
        $this->name       = $name;
        $this->value      = $value;
    }

    public function __clone() {
        $this->name = clone $this->name;
        $this->name->parentnode = $this;
        $this->value = clone $this->value;
        $this->value->parentnode = $this;
    }

    public function getChildren() {
        return [$this->name, $this->value];
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $this->name->remap_position_data($offset + 1);
        $this->value->remap_position_data($offset + 2 + core_text::strlen($this->name->toString()));
    }

    public function toString($params = null): string {
        return ',' . $this->name->toString($params) . '=' . $this->value->toString($params);
    }

    public function replace($node, $with) {
        if ($this->name === $node) {
            $this->name = $with;
        } else if ($this->value === $node) {
            $this->value = $with;
        }
    }
}

class MP_Statement extends MP_Node {
    public $statement = null;
    public $flags     = null;

    public function __construct($statement, $flags) {
        parent::__construct();
        $this->statement = $statement;
        $this->flags     = $flags;
    }

    public function __clone() {
        if ($this->flags !== null && count($this->flags) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->flags); $i++) {
                $this->flags[$i] = clone $this->flags[$i];
                $this->flags[$i]->parentnode = $this;
            }
        }
        $this->statement = clone $this->statement;
        $this->statement->parentnode = $this;
    }

    public function getChildren() {
        return array_merge([$this->statement], $this->flags);
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $this->statement->remap_position_data($offset);
        $itemoffset = $offset + core_text::strlen($this->statement->toString());
        foreach ($this->flags as $flag) {
            $flag->remap_position_data($itemoffset);
            $itemoffset = $itemoffset + core_text::strlen($flag->toString());
        }
    }

    public function toString($params = null): string {
        $r = $this->statement->toString($params);

        foreach ($this->flags as $flag) {
            $r .= $flag->toString($params);
        }

        return $r;
    }

    public function replace($node, $with) {

        foreach ($this->flags as $key => $value) {
            if ($value === $node) {
                $this->flags[$key] = $with;
            }
        }
        if ($this->statement === $node) {
            $this->statement = $with;
        }
    }
}

class MP_Prefixeq extends MP_Node {
    public $statement = null;

    public function __construct($statement) {
        parent::__construct();
        $this->statement = $statement;
    }

    public function __clone() {
        $this->statement = clone $this->statement;
        $this->statement->parentnode = $this;
    }

    public function getChildren() {
        return [$this->statement];
    }

    public function toString($params = null): string {
        $indent = '';
        if (isset($params['pretty']) && is_integer($params['pretty'])) {
            $indent = str_pad($indent, $params['pretty']);
        }

        if (isset($params['inputform']) && $params['inputform'] === true) {
            return $indent . '=' . $this->statement->toString($params);
        }
        $r = $indent . 'stackeq(' . $this->statement->toString($params) . ')';

        return $r;
    }

    public function replace($node, $with) {

        if ($this->statement === $node) {
            $this->statement = $with;
        }
    }
}

class MP_Let extends MP_Node {
    public $statement = null;

    public function __construct($statement) {
        parent::__construct();
        $this->statement = $statement;
    }

    public function __clone() {
        $this->statement = clone $this->statement;
        $this->statement->parentnode = $this;
    }

    public function getChildren() {
        return [$this->statement];
    }

    public function toString($params = null): string {
        $indent = '';
        if (is_integer($params['pretty'])) {
            $indent = str_pad($indent, $params['pretty']);
        }

        if (isset($params['inputform']) && $params['inputform'] === true) {
            return $indent . stack_string('equiv_LET') . ' ' .
                    $this->statement->toString($params);
        }
        $r = $indent . 'stacklet(' . $this->statement->lhs->toString($params) .',' .
            $this->statement->rhs->toString($params) . ')';

        return $r;
    }

    public function replace($node, $with) {

        if ($this->statement === $node) {
            $this->statement = $with;
        }
    }
}

class MP_Root extends MP_Node {
    public $items = null;

    public function __construct($items) {
        parent::__construct();
        $this->items    = $items;
    }

    public function __clone() {
        if ($this->items !== null && count($this->items) > 0) {
            $i = 0;
            for ($i = 0; $i < count($this->items); $i++) {
                $this->items[$i] = clone $this->items[$i];
                $this->items[$i]->parentnode = $this;
            }
        }
    }

    public function getChildren() {
        return $this->items;
    }

    public function remap_position_data(int $offset=0) {
        $total = $this->toString();
        $this->position['start'] = $offset;
        $this->position['end'] = $offset + core_text::strlen($total);
        $itemoffset = $offset;
        foreach ($this->items as $item) {
            $item->remap_position_data($itemoffset);
            $itemoffset = $itemoffset + core_text::strlen($item->toString());
        }
    }

    public function toString($params = null): string {
        $r = '';

        foreach ($this->items as $item) {
            $r .= $item->toString($params);
        }

        if (!isset($params['nosemicolon'])) {
            $r .= ";\n";
        }

        return $r;
    }

    public function replace(
        $node,
        $with
    ) {
        foreach ($this->items as $key => $value) {
            if ($value === $node) {
                $this->items[$key] = $with;
            }
        }
    }
}
// These are required by the parser. They are defined here instead of the parser
// to avoid redeclaration. Basically, problem with the PHP target generator.
function opLBind($op) {
    switch ($op) {
        case ':':
        case '::':
        case ':=':
        case '::=':
            return 180;
        case '!':
        case '!!':
            return 160;
        case '^':
            return 140;
        case '.':
            return 130;
        case '*':
        case '/':
            return 120;
        case '+':
        case '-':
            return 100;
        case '=':
        case '*':
        case '#':
        case '>':
        case '>=':
        case '<':
        case '<=':
            return 80;
        case 'and':
        case 'nounand':
            return 65;
        case 'or':
        case 'nounor':
            return 60;
    }
    return 0;
}

function opRBind($op) {
    switch ($op) {
        case ':':
        case '::':
        case ':=':
        case '::=':
            return 20;
        case '^':
            return 139;
        case '.':
            return 129;
        case '*':
        case '/':
            return 120;
        case '+':
            return 100;
        case '-':
            return 134;
        case '=':
        case '#':
        case '>':
        case '>=':
        case '<':
        case '<=':
            return 80;
        case 'not':
            return 70;
    }
    return 0;
}

function opBind($op) {
    if (!($op instanceof MP_Operation)) {
        return $op;
    }
    // This one is not done with STACK.

    /*
    if ($op->op == '-') {
    $op->op='+';
    $pos = $op->rhs->position;
    $op->rhs=new MP_PrefixOp('-',$op->rhs);
    $op->rhs->position = $pos;
    $op->rhs->rhs->parentnode = $op->rhs;
    $op->rhs->parentnode = $op;
    }
     */
    $op->lhs = opBind($op->lhs);
    $op->rhs = opBind($op->rhs);
    if ($op->lhs instanceof MP_Operation && (opLBind($op->op) > opRBind($op->lhs->op))) {
        $posa = mergePosition($op->lhs->position, $op->position);
        $posb = mergePosition($op->lhs->rhs->position, $op->rhs->position);
            $nop = new MP_Operation($op->lhs->op, $op->lhs->lhs,
                    new MP_Operation($op->op, $op->lhs->rhs, $op->rhs));
        $nop->position             = $posa;
        $nop->rhs->position        = $posb;
        $nop->parentnode           = $op->parentnode;
        $nop->lhs->parentnode      = $nop;
        $nop->rhs->parentnode      = $nop;
        $nop->rhs->lhs->parentnode = $nop->rhs;
        $nop->rhs->rhs->parentnode = $nop->rhs;
        $op                        = $nop;
        $op                        = opBind($op);
    }
    if (!($op instanceof MP_PostfixOp) && $op->rhs instanceof MP_Operation &&
            (opRBind($op->op) > opLBind($op->rhs->op))) {
        $posa = mergePosition($op->rhs->position, $op->position);
        $posb = mergePosition($op->lhs->position, $op->rhs->lhs->position);
        $nop = new MP_Operation($op->rhs->op,
                new MP_Operation($op->op, $op->lhs, $op->rhs->lhs), $op->rhs->rhs);
        $nop->position             = $posa;
        $nop->lhs->position        = $posb;
        $nop->parentnode           = $op->parentnode;
        $nop->lhs->parentnode      = $nop;
        $nop->rhs->parentnode      = $nop;
        $nop->lhs->lhs->parentnode = $nop->lhs;
        $nop->lhs->rhs->parentnode = $nop->lhs;
        $op                        = $nop;
        $op                        = opBind($op);
    }
    if (!($op instanceof MP_PrefixOp) && $op->lhs instanceof MP_PrefixOp
            && (opLBind($op->op) > opRBind($op->lhs->op))) {
        $posa = mergePosition($op->lhs->position, $op->position);
        $posb = mergePosition($op->lhs->rhs->position, $op->rhs->position);
        $nop = new MP_PrefixOp($op->lhs->op, new MP_Operation($op->op, $op->lhs->rhs, $op->rhs));
        $nop->position             = $posa;
        $nop->rhs->position        = $posb;
        $nop->parentnode           = $op->parentnode;
        $nop->rhs->parentnode      = $nop;
        $nop->rhs->lhs->parentnode = $nop->rhs;
        $nop->rhs->rhs->parentnode = $nop->rhs;
        $op                        = $nop;
        $op                        = opBind($op);
    }
    if (!($op instanceof MP_PostfixOp) && $op->rhs instanceof MP_PostfixOp &&
            (opRBind($op->op) > opLBind($op->rhs->op))) {
        $posa = mergePosition($op->rhs->position, $op->position);
        $posb = mergePosition($op->lhs->position, $op->rhs->lhs->position);
        $nop = new MP_PostfixOp($op->rhs->op,
                new MP_Operation($op->op, $op->lhs, $op->rhs->lhs));
        $nop->position             = $posa;
        $nop->lhs->position        = $posb;
        $nop->parentnode           = $op->parentnode;
        $nop->lhs->parentnode      = $nop;
        $nop->lhs->lhs->parentnode = $nop->lhs;
        $nop->lhs->rhs->parentnode = $nop->lhs;
        $op                        = $nop;
        $op                        = opBind($op);
    }
    return $op;
}

function mergePosition($posa, $posb) {
    // The position detail is a bit less verbose on the PHP parser as it costs to evaluate and the library does not have it.
    $r = ['start' => $posa['start'],
        'end' => $posa['end']];
    if ($posb['start'] < $r['start']) {
        $r['start'] = $posb['start'];
    }

    if ($posb['end'] > $r['end']) {
        $r['end'] = $posb['end'];
    }

    return $r;
}