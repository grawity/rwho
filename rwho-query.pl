#!/usr/bin/perl
use warnings;
use strict;

use constant
	NOTIFY_URL => 'http://equal.cluenet.org/~grawity/misc/rwho.php';

use LWP::UserAgent;
use JSON;
use POSIX qw/strftime/;

sub prettyprint(@) {
	my ($host, $user, $rhost, $line, $time) = @_;
	printf "%-12s %-20s %s  %s\n",
		($user, "$host:$line", strftime("%F %R", localtime $time),
			$rhost || '(local login)');
}

sub fetch() {
	my $ua = LWP::UserAgent->new;
	my $resp = $ua->get(NOTIFY_URL);
	my $data = decode_json($resp->decoded_content);
	my @data = sort {$a->{user} cmp $b->{user}} @$data;
	if (scalar @data) {
		for my $entry (@data) {
			prettyprint @{$entry}{qw(host user rhost line time)};
		}
	} else {
		print "Nobody's on.\n";
	}
}

fetch;
