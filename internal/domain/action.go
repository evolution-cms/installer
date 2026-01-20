package domain

type ActionType string

const (
	ActionAnswerSelect   ActionType = "answer_select"
	ActionAnswerInput    ActionType = "answer_input"
	ActionExtrasDecision ActionType = "extras_decision"
)

type Action struct {
	Type ActionType

	QuestionID string
	OptionID   string
	Text       string
	Values     []string
	Extras     []ExtrasSelection
}
