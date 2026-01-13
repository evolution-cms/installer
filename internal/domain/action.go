package domain

type ActionType string

const (
	ActionAnswerSelect ActionType = "answer_select"
	ActionAnswerInput  ActionType = "answer_input"
)

type Action struct {
	Type ActionType

	QuestionID string
	OptionID   string
	Text       string
}

