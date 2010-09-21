#!/usr/bin/perl
use warnings;
use strict;

use constant
	NOTIFY_URL => 'http://equal.cluenet.org/~grawity/misc/rwho.php';

use LWP::UserAgent;
use JSON;
use POSIX qw/strftime/;

my %last = (user => "", host => "");

sub prettyprint {
	my ($entry) = @_;
	my %entry = %$entry;
	printf "%-12s %-12s %-8s %s  %s\n",
		($entry{user} ne $last{user} ? $entry{user} : ""),
		$entry{host},
		$entry{line},
		strftime("%F %R", localtime $entry{time}),
		$entry{rhost} || '(local login)';

	%last = %entry;
}

sub fetch() {
	my $ua = LWP::UserAgent->new;
	my $resp = $ua->get(NOTIFY_URL);
	my $data = decode_json($resp->decoded_content);
	my @data = sort {
		$a->{user} cmp $b->{user}
		or $a->{host} cmp $b->{host}
		#or -($a->{time} <=> $b->{time})
		} @$data;
	if (scalar @data) {
		prettyprint $_ for @data;
	} else {
		print "Nobody's on.\n";
	}
}

fetch;
