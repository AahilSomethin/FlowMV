package contracts

import "testing"

func TestStrictJSON(t *testing.T) {
	for _, raw := range []string{`{"summary":"a","summary":"b"}`, `{"unknown":true}`, `{} {}`, `{"uncertain":"yes"}`, `[]`} {
		t.Run(raw, func(t *testing.T) {
			var result Advice
			if Decode([]byte(raw), &result) == nil {
				t.Fatal("unsafe JSON accepted")
			}
		})
	}
}
func TestAdviceCannotInventFieldsRoutesOrRecipients(t *testing.T) {
	r := Request{Fields: []Field{{ID: "employee_id", Required: true}}, Categories: []string{"leave"}, Routes: []string{"leave_review"}}
	valid := Advice{Classification: "leave", Fields: map[string]string{"employee_id": "DEMO-1"}, Recommendation: "review"}
	if valid.Validate(r) != nil {
		t.Fatal("valid advisory rejected")
	}
	for _, mutate := range []func(*Advice){func(a *Advice) { a.Fields = map[string]string{"recipient": "attacker@example.com"} }, func(a *Advice) { a.Route = "unapproved_route" }, func(a *Advice) { a.Recommendation = "approve" }, func(a *Advice) { a.Fields = map[string]string{} }, func(a *Advice) { a.Classification = "unapproved" }} {
		a := valid
		mutate(&a)
		if a.Validate(r) == nil {
			t.Fatal("authority escaped structured contract")
		}
	}
}
func TestAdviceRequiresExplicitUncertaintyAndRejectsNullOrDuplicateMembers(t *testing.T) {
	valid := `{"summary":"Synthetic","classification":"unknown","fields":{},"missing":[],"inconsistent":[],"route":"","draft_reply":"","recommendation":"review","uncertain":false}`
	var advice Advice
	if DecodeAdvice([]byte(valid), &advice) != nil {
		t.Fatal("valid advice refused")
	}
	for _, invalid := range []string{
		`{"summary":"Synthetic","classification":"unknown","fields":{},"missing":[],"inconsistent":[],"route":"","draft_reply":"","recommendation":"review"}`,
		`{"summary":"Synthetic","classification":"unknown","fields":{},"missing":[],"inconsistent":[],"route":"","draft_reply":"","recommendation":"review","uncertain":null}`,
		`{"summary":"Synthetic","classification":"unknown","fields":{},"missing":[],"inconsistent":[],"route":"","draft_reply":"","recommendation":"review","uncertain":true,"uncertain":false}`,
	} {
		if DecodeAdvice([]byte(invalid), &advice) == nil {
			t.Fatal("incomplete or ambiguous advice accepted")
		}
	}
}
